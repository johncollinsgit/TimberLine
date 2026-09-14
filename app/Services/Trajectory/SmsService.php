<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Event;
use App\Models\Trajectory\Notification;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SmsService
{
    public function verify(User $user, Space $space, string $phone): void
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless(config('trajectory.sms_enabled'), 503);
        $code = (string) random_int(100000, 999999);
        $key = 'trajectory:phone:'.$user->id.':'.$space->id;
        $result = app(TwilioSmsService::class)->sendSms($phone, 'Everbranch Trajectory verification code: '.$code.'. Expires in 10 minutes.', ['tenant_id' => $space->tenant_id, 'idempotency_key' => $key.':'.Str::uuid()]);
        abort_unless(($result['success'] ?? false) && ! ($result['dry_run'] ?? false), 503, 'Text provider is not ready.');
        Cache::put($key, encrypt(['phone' => $phone, 'hash' => hash('sha256', $code)]), now()->addMinutes(10));
    }

    public function confirm(User $user, Space $space, string $code): void
    {
        app(FinanceAccess::class)->authorize($user, $space);
        $key = 'trajectory:phone:'.$user->id.':'.$space->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 600);
        $value = Cache::get($key);
        abort_unless($value, 410);
        $value = decrypt($value);
        abort_unless(hash_equals($value['hash'], hash('sha256', $code)), 422, 'Verification code does not match.');
        Record::updateOrCreate(['space_id' => $space->id, 'kind' => 'sms', 'name' => (string) $user->id], ['data' => ['user_id' => $user->id, 'phone' => $value['phone'], 'consented_at' => now()->toIso8601String(), 'verified_at' => now()->toIso8601String()], 'active' => true]);
        Cache::forget($key);
    }

    public function send(Space $space, Record $preference, string $body, string $dedupe, ?Transaction $tx = null): void
    {
        $user = User::find($preference->data['user_id']);
        if (! $user || ! config('trajectory.sms_enabled') || ! app(FinanceAccess::class)->allows($user, $space) || ! $preference->active) {
            return;
        }
        $phone = $preference->data['phone'];
        Cache::lock('trajectory:sms:'.hash('sha256', $dedupe), 60)->get(function () use ($space, $user, $phone, $body, $dedupe, $tx): void {
            if (Notification::where('dedupe_key', $dedupe)->exists()) {
                return;
            }
            $token = $tx ? strtoupper(Str::random(12)) : null;
            $message = $body.($token ? ' Reply '.$token.' FACE, BS, or OK to classify this expense.' : '').' Reply STOP to opt out.';
            $notification = Notification::create(['space_id' => $space->id, 'user_id' => $user->id, 'transaction_id' => $tx?->id, 'dedupe_key' => $dedupe, 'phone' => $phone, 'body' => $message, 'reply_hash' => $token ? hash('sha256', $token) : null, 'expires_at' => $token ? now()->addDay() : null]);
            $result = app(TwilioSmsService::class)->sendSms($phone, $message, ['tenant_id' => $space->tenant_id, 'idempotency_key' => $dedupe]);
            $notification->update(['status' => ($result['success'] ?? false) && ! ($result['dry_run'] ?? false) ? 'sent' : 'failed', 'provider_id' => $result['provider_message_id'] ?? null]);
        });
    }

    public function reply(string $phone, string $body, string $messageId): void
    {
        $body = strtoupper(trim($body));
        if (in_array($body, ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            foreach (Record::where('kind', 'sms')->where('active', true)->get() as $record) {
                if (hash_equals($record->data['phone'], $phone)) {
                    $record->update(['active' => false]);
                }
            }

            return;
        }
        if (! preg_match('/^([A-Z0-9]{12})\s+(FACE|BS|OK)$/', $body, $matches)) {
            return;
        }
        DB::transaction(function () use ($phone, $matches, $messageId): void {
            $notification = Notification::where('reply_hash', hash('sha256', $matches[1]))->lockForUpdate()->first();
            if (! $notification || $notification->status !== 'sent' || $notification->consumed_at || ! $notification->expires_at?->isFuture() || ! hash_equals($notification->phone, $phone)) {
                return;
            }
            $space = Space::find($notification->space_id);
            $user = User::find($notification->user_id);
            if (! $space || ! $user || ! app(FinanceAccess::class)->allows($user, $space)) {
                return;
            }
            $preference = Record::where('space_id', $space->id)->where('kind', 'sms')->where('name', (string) $user->id)->where('active', true)->first();
            if (! $preference || ! hash_equals($preference->data['phone'], $phone)) {
                return;
            }
            $tx = Transaction::where('space_id', $space->id)->whereKey($notification->transaction_id)->lockForUpdate()->first();
            if (! $tx || str_starts_with($tx->flow, 'medical_') || $tx->removed || $tx->pending || $tx->updated_at->gt($notification->created_at)) {
                return;
            }
            app(LedgerService::class)->classify($user, $space, $tx, ['version' => $tx->version, 'category' => $tx->category, 'flow' => $tx->flow, 'face_punched' => $matches[2] === 'FACE', 'bullshit_spending' => $matches[2] === 'BS']);
            $notification->update(['consumed_at' => now()]);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'sms_classification', 'record_id' => $tx->id, 'after' => ['provider_message_hash' => hash('sha256', $messageId)]]);
        });
    }
}
