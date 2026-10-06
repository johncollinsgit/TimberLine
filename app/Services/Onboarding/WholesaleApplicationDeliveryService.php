<?php

namespace App\Services\Onboarding;

use App\Models\CustomerAccessRequest;
use App\Notifications\WholesaleApplicationDecisionNotification;
use App\Notifications\WholesaleApplicationReviewNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class WholesaleApplicationDeliveryService
{
    public function enqueue(CustomerAccessRequest $request, string $kind, bool $force = false): void
    {
        $metadata = $request->metadata ?? [];
        if (! $force && isset($metadata['delivery'][$kind])) {
            return;
        }
        $metadata['delivery'][$kind] = ['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()->toIso8601String()];
        $request->forceFill(['metadata' => $metadata])->save();
    }

    public function deliver(int $id): void
    {
        // Bound individual provider calls and prevent overlapping scheduler runs.
        $lock = Cache::lock('wholesale-application-delivery:'.$id, 300);
        if (! $lock->get()) {
            return;
        }
        try {
            foreach (['review', 'decision'] as $kind) {
                $request = CustomerAccessRequest::wholesaleApplications()->findOrFail($id);
                $state = data_get($request->metadata, 'delivery.'.$kind);
                if (! $state || $state['status'] === 'sent' || now()->lt($state['next_attempt_at'])) {
                    continue;
                }
                $attempts = (int) ($state['attempts'] ?? 0) + 1;
                try {
                    if ($kind === 'review') {
                        Notification::route('mail', app(WholesaleApplicationReviewInboxResolver::class)->resolve($request))
                            ->notify(new WholesaleApplicationReviewNotification($request));
                    } else {
                        if (! in_array($request->status, ['approved', 'rejected'], true)) {
                            continue;
                        }
                        Notification::route('mail', $request->email)->notify(new WholesaleApplicationDecisionNotification($request));
                    }
                    $state = ['status' => 'sent', 'attempts' => $attempts, 'sent_at' => now()->toIso8601String(), 'next_attempt_at' => null];
                } catch (\Throwable $e) {
                    report($e);
                    $state = ['status' => 'failed', 'attempts' => $attempts, 'next_attempt_at' => now()->addMinutes(min(60, 2 ** min($attempts, 6)))->toIso8601String()];
                }
                DB::transaction(function () use ($id, $kind, $state) {
                    $current = CustomerAccessRequest::lockForUpdate()->findOrFail($id);
                    $metadata = $current->metadata ?? [];
                    $metadata['delivery'][$kind] = $state;
                    $current->metadata = $metadata;
                    if ($kind === 'decision') {
                        $current->activation_email_last_attempted_at = now();
                        $current->activation_email_last_attempt_status = $state['status'];
                        if ($state['status'] === 'sent') {
                            $current->activation_email_last_sent_at = now();
                            $current->activation_email_sent_at ??= now();
                        }
                    }
                    $current->save();
                });
            }
        } finally {
            $lock->release();
        }
    }
}
