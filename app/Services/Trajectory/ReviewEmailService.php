<?php

namespace App\Services\Trajectory;

use App\Mail\TrajectoryReviewMail;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ReviewEmailService
{
    public function preferences(User $user, Space $space): array
    {
        $saved = Event::where('space_id', $space->id)->where('actor_id', $user->id)
            ->where('action', 'review_email_preference')->latest('id')->first()?->after ?? [];

        return [
            'frequency' => ($saved['recipient_hash'] ?? null) === hash('sha256', $user->email) ? ($saved['frequency'] ?? 'off') : 'off',
            'timezone' => $saved['timezone'] ?? $space->timezone,
            'email' => $user->email,
            'delivery_ready' => $this->deliveryReady(),
        ];
    }

    public function save(User $user, Space $space, string $frequency, string $timezone): array
    {
        validator(compact('frequency', 'timezone'), ['frequency' => 'required|in:off,daily,weekly', 'timezone' => 'required|timezone'])->validate();
        DB::transaction(function () use ($user, $space, $frequency, $timezone): void {
            $locked = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            app(FinanceAccess::class)->authorize($user->fresh(), $locked);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'review_email_preference',
                'after' => ['frequency' => $frequency, 'timezone' => $timezone, 'recipient_hash' => hash('sha256', $user->email)]]);
        });

        return $this->preferences($user, $space);
    }

    public function due(array $preferences): bool
    {
        $now = CarbonImmutable::now($preferences['timezone']);

        return $preferences['frequency'] !== 'off' && $now->hour === 9
            && ($preferences['frequency'] === 'daily' || $now->isMonday());
    }

    public function deliveryReady(): bool
    {
        // Never silently copy financial emails into a log or treat a log fallback as delivery.
        return in_array(config('mail.mailers.'.config('trajectory.review_email_mailer', config('mail.default')).'.transport'),
            ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail'], true);
    }

    public function send(int $userId, int $spaceId, bool $now = false): string
    {
        if (! $this->deliveryReady()) {
            return 'provider_unavailable';
        }
        $prepared = DB::transaction(function () use ($userId, $spaceId, $now): ?array {
            $space = Space::whereKey($spaceId)->lockForUpdate()->first();
            $user = User::find($userId);
            if (! $user || ! $space || ! app(FinanceAccess::class)->allows($user, $space)) {
                return null;
            }
            $preferences = $this->preferences($user, $space);
            if ($preferences['frequency'] === 'off' || (! $now && ! $this->due($preferences))) {
                return null;
            }
            $local = CarbonImmutable::now($preferences['timezone']);
            $since = ($preferences['frequency'] === 'weekly' ? $local->startOfWeek() : $local->startOfDay())->utc();
            // Serialize claims using the finance-space row. A retry or worker crash cannot
            // send twice in this period; unknown provider outcomes require operator review.
            if (Event::where('space_id', $spaceId)->where('actor_id', $userId)->where('action', 'review_email_claimed')
                ->where('created_at', '>=', $since)->exists()) {
                return null;
            }
            $rows = collect(app(LedgerService::class)->entries($space, null, CarbonImmutable::now($space->timezone)->toDateString()))
                ->where('reviewed', false)->sort(fn ($a, $b) => strcmp($b['date'], $a['date']) ?: $b['id'] <=> $a['id'])->values();
            if ($rows->isEmpty()) {
                return null;
            }
            $claim = Event::create(['space_id' => $spaceId, 'actor_id' => $userId, 'action' => 'review_email_claimed',
                'after' => ['count' => $rows->count(), 'frequency' => $preferences['frequency']]]);

            return [$user, $space, $rows->take(4)->all(), $rows->count(), $claim];
        });
        if (! $prepared) {
            return 'skipped';
        }
        [$user, $space, $rows, $count, $claim] = $prepared;
        try {
            Mail::mailer(config('trajectory.review_email_mailer', config('mail.default')))->to($user->email)
                ->send(new TrajectoryReviewMail($user->name, $space->name, $space->id, $count, $rows));
            $status = 'accepted';
        } catch (\Throwable) {
            // Do not log provider exceptions: they may contain addresses or message bodies.
            $status = 'uncertain';
        }
        Event::create(['space_id' => $spaceId, 'actor_id' => $userId, 'action' => 'review_email_'.$status,
            'record_id' => $claim->id, 'after' => ['count' => $count]]);

        return $status;
    }
}
