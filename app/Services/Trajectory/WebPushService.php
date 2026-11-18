<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\AlertPreference;
use App\Models\Trajectory\ExpenseNotice;
use App\Models\Trajectory\PushSubscription;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function ready(): bool
    {
        return config('trajectory_mobile.push_enabled') && filled(config('trajectory_mobile.public_key'))
            && filled(config('trajectory_mobile.private_key')) && filled(config('trajectory_mobile.subject'));
    }

    /** Endpoint allowlist is also an SSRF boundary; never send to an arbitrary client URL. */
    public function validEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');
        foreach (config('trajectory_mobile.allowed_push_hosts', []) as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    public function send(ExpenseNotice $notice): void
    {
        $space = Space::find($notice->space_id);
        $user = User::find($notice->user_id);
        $preference = AlertPreference::where('space_id', $notice->space_id)->where('user_id', $notice->user_id)->first();
        if (! $space || ! $user || ! $preference?->enabled || ! app(FinanceAccess::class)->allows($user, $space)) {
            $notice->update(['delivery_status' => 'suppressed']);

            return;
        }
        $transaction = Transaction::where('space_id', $space->id)->find($notice->transaction_id);
        $account = $transaction ? Account::where('space_id', $space->id)->find($transaction->account_id) : null;
        if (! $transaction || ! $account || ! in_array($account->kind, ['cash', 'credit'], true)
            || $transaction->removed || $transaction->amount_cents >= 0
            || ! in_array($transaction->flow, ['expense', 'medical_payment', 'medical_membership'], true)
            || ($transaction->pending && ! $preference->include_pending)) {
            $notice->update(['delivery_status' => 'suppressed']);

            return;
        }
        $payload = app(ExpenseNotificationService::class)->payload($user, $space, $transaction);
        if (! $this->ready()) {
            $notice->update(['delivery_status' => 'inbox_only']);

            return;
        }
        $subscriptions = PushSubscription::where('space_id', $space->id)->where('user_id', $user->id)->whereNull('disabled_at')->get();
        if ($subscriptions->isEmpty()) {
            $notice->update(['delivery_status' => 'inbox_only']);

            return;
        }
        $claimed = DB::transaction(function () use ($notice): bool {
            $locked = ExpenseNotice::whereKey($notice->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_status !== 'pending') {
                return false;
            }
            $locked->update(['delivery_status' => 'dispatching']);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $body = 'Open Trajectory to review your purchase.';
        if ($preference->show_details) {
            $body = $payload['merchant'].' · $'.number_format($payload['amount_cents'] / 100, 2).' · '.$payload['category_label'];
            if ($payload['remaining_cents'] !== null) {
                $body .= ' · $'.number_format($payload['remaining_cents'] / 100, 2).' budget left';
            }
            if ($payload['pending']) {
                $body .= ' · pending';
            }
        }
        try {
            $push = $this->transport();
            foreach ($subscriptions as $subscription) {
                if (! $this->validEndpoint($subscription->endpoint)) {
                    continue;
                }
                $push->queueNotification(Subscription::create(['endpoint' => $subscription->endpoint, 'keys' => $subscription->keys, 'contentEncoding' => $subscription->content_encoding]), json_encode([
                    'title' => 'Ask Everbranch · New purchase', 'body' => $body, 'url' => $payload['url'],
                    'tag' => 'trajectory-expense-'.$notice->id, 'notice_id' => $notice->id,
                ], JSON_THROW_ON_ERROR));
            }
            $accepted = false;
            foreach ($push->flush() as $report) {
                $accepted = $accepted || $report->isSuccess();
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->update(['disabled_at' => now()]);
                }
            }
            $notice->update(['delivery_status' => $accepted ? 'accepted' : 'failed', 'delivered_at' => $accepted ? now() : null]);
        } catch (\Throwable) {
            // Unknown submission outcomes are not silently sent a second time.
            $notice->update(['delivery_status' => 'unconfirmed']);
        }
    }

    protected function transport(): WebPush
    {
        return new WebPush(['VAPID' => ['subject' => config('trajectory_mobile.subject'), 'publicKey' => config('trajectory_mobile.public_key'), 'privateKey' => config('trajectory_mobile.private_key')]], ['TTL' => 86400], 20, ['allow_redirects' => false]);
    }
}
