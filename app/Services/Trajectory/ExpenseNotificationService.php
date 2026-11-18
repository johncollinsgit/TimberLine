<?php

namespace App\Services\Trajectory;

use App\Jobs\Trajectory\SendExpensePush;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\AlertPreference;
use App\Models\Trajectory\AllocationDetail;
use App\Models\Trajectory\ExpenseNotice;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpenseNotificationService
{
    private array $budgetSnapshots = [];

    public function capture(Transaction $transaction): void
    {
        if (! config('trajectory.enabled')) {
            return;
        }
        $space = Space::find($transaction->space_id);
        $account = Account::find($transaction->account_id);
        if (! $space || $space->kind !== 'household' || ! $account || ! in_array($account->kind, ['cash', 'credit'], true)) {
            return;
        }
        $eligible = $transaction->amount_cents < 0 && ! $transaction->removed && $transaction->posted_on->toDateString() <= now($space->timezone)->toDateString()
            && in_array($transaction->flow, ['expense', 'medical_payment', 'medical_membership'], true);
        foreach (AlertPreference::where('space_id', $space->id)->where('enabled', true)->get() as $preference) {
            $user = User::find($preference->user_id);
            if (! $user || ! app(FinanceAccess::class)->allows($user, $space)) {
                continue;
            }
            $existing = ExpenseNotice::where('user_id', $user->id)->where('transaction_id', $transaction->id)->first();
            if (! $eligible) {
                if ($existing) {
                    $existing->update(['payload' => [...$existing->payload, 'removed' => true]]);
                }

                continue;
            }
            if (! $preference->enabled_at || $transaction->created_at->lt($preference->enabled_at)
                || ($existing === null && ! $transaction->pending && $transaction->posted_on->toDateString() < $preference->enabled_at->setTimezone($space->timezone)->toDateString())
                || ($transaction->pending && ! $preference->include_pending)) {
                continue;
            }
            $payload = $this->payload($user, $space, $transaction);
            $notice = DB::transaction(function () use ($space, $user, $transaction, $payload): ExpenseNotice {
                $notice = ExpenseNotice::firstOrCreate(['user_id' => $user->id, 'transaction_id' => $transaction->id], [
                    'space_id' => $space->id, 'payload' => $payload, 'delivery_status' => 'pending',
                ]);
                if (! $notice->wasRecentlyCreated) {
                    $notice->update(['payload' => $payload]);
                }

                return $notice;
            });
            if ($notice->wasRecentlyCreated) {
                SendExpensePush::dispatch($notice->id)->afterCommit();
            }
        }
    }

    public function payload(User $user, Space $space, Transaction $transaction): array
    {
        app(FinanceAccess::class)->authorize($user, $space);
        $categoryKey = $transaction->category;
        $category = $space->settings['income_categories'][$categoryKey] ?? ucfirst(str_replace('_', ' ', $categoryKey));
        $programId = null;
        $programLabel = null;
        $details = AllocationDetail::where('transaction_id', $transaction->id)->where('space_id', $space->id)->where('active', true)->get();
        $validDetails = $details->isNotEmpty() && $details->every(fn ($detail) => $detail->source_fingerprint === app(AttributionService::class)->fingerprint($transaction));
        if ($validDetails) {
            if ($details->count() === 1 && $details->first()->amount_cents === $transaction->amount_cents) {
                $detail = $details->first();
                $child = $detail->subcategory_record_id ? Record::where('space_id', $space->id)->where('kind', 'category_definition')->find($detail->subcategory_record_id) : null;
                $categoryKey = $child?->data['key'] ?? $detail->category;
                $category = $child?->name ?? $space->settings['income_categories'][$categoryKey] ?? ucfirst(str_replace('_', ' ', $categoryKey));
                $programId = $detail->program_record_id;
                $programLabel = $programId ? Record::where('space_id', $space->id)->where('kind', 'program')->find($programId)?->name : null;
            } else {
                $categoryKey = 'split_purchase';
                $category = 'Split purchase';
            }
        }
        $remaining = null;
        if (class_exists(BudgetEnvelopeService::class)) {
            $month = substr($transaction->posted_on->toDateString(), 0, 7);
            $cacheKey = $space->id.':'.$month.':'.($programId ?? 'all');
            $budget = $this->budgetSnapshots[$cacheKey] ??= app(BudgetEnvelopeService::class)->build($space, $month, $programId);
            $matches = collect($budget['rows'] ?? [])->where('category', $categoryKey);
            if ($matches->count() === 1) {
                $remaining = $matches->first()['remaining_cents'] ?? null;
            }
        }

        return [
            'transaction_id' => $transaction->id, 'version' => $transaction->version,
            'merchant' => $transaction->merchant, 'amount_cents' => abs($transaction->amount_cents),
            'date' => $transaction->posted_on->toDateString(), 'category' => $categoryKey,
            'category_label' => $category, 'remaining_cents' => $remaining, 'pending' => $transaction->pending,
            'program_id' => $programId, 'program_label' => $programLabel,
            'needs_purpose' => ! $transaction->reviewed || $categoryKey === 'uncategorized',
            'question' => (! $transaction->reviewed || $categoryKey === 'uncategorized') ? 'What was this purchase for?' : null,
            'url' => '/trajectory/personal/?space='.$space->id.'&transaction='.$transaction->id,
            'feed_timing' => 'Received from the bank feed; purchase time and feed arrival may differ.',
        ];
    }
}
