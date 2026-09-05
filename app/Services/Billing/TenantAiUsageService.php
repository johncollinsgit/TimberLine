<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBudSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantAiUsageService
{
    public function reserveVoice(Tenant $tenant, User $user, string $clientUuid, string $context, string $model, int $seconds): TenantAiUsageEvent
    {
        abort_unless((bool) config('bud.ai_enabled') && (bool) config('bud.provider_configured'), 503, 'Paid AI is not available yet.');

        return DB::transaction(function () use ($tenant, $user, $clientUuid, $context, $model, $seconds): TenantAiUsageEvent {
            $existing = TenantAiUsageEvent::query()->forTenantId((int) $tenant->id)->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }

            $setting = TenantBudSetting::query()->where('tenant_id', (int) $tenant->id)->lockForUpdate()->first();
            abort_unless($setting?->ai_status === 'approved', 403, 'Paid AI must be approved for this workspace.');
            if (! $setting->ai_period_started_at || ! $setting->ai_period_started_at->isSameMonth(now())) {
                $setting->forceFill(['ai_used_cents' => 0, 'ai_period_started_at' => now()->startOfMonth()])->save();
            }

            $seconds = max(1, min(90, $seconds));
            $providerRate = max(0, (int) config('services.openai.field_voice_provider_cost_micros_per_minute', 4500));
            $buyerRate = max($providerRate, (int) config('services.openai.field_voice_buyer_rate_micros_per_minute', 4500));
            // Reserve the full recording ceiling so a forged client duration
            // cannot push the workspace past its operator-approved hard cap.
            $buyerCharge = (int) ceil(90 * $buyerRate / 60);
            $committed = (int) TenantAiUsageEvent::query()->forTenantId((int) $tenant->id)
                ->whereIn('status', ['reserved', 'settled'])->where('occurred_at', '>=', now()->startOfMonth())->sum('buyer_charge_micros');
            $budgetMicros = max(0, (int) $setting->ai_monthly_budget_cents) * 10000;
            abort_unless($budgetMicros > 0 && $committed + $buyerCharge <= $budgetMicros, 429, 'This workspace has reached its monthly paid AI limit.');

            return TenantAiUsageEvent::query()->create([
                'tenant_id' => (int) $tenant->id, 'user_id' => (int) $user->id, 'client_uuid' => $clientUuid,
                'feature' => 'field_voice_transcription', 'context' => $context, 'model' => $model, 'status' => 'reserved',
                'duration_seconds' => $seconds, 'provider_cost_micros' => (int) ceil($seconds * $providerRate / 60),
                'buyer_charge_micros' => $buyerCharge, 'occurred_at' => now(),
                'metadata' => ['billing_scope' => 'tenant_monthly', 'pricing_version' => 'tenant-ai-v1', 'reserved_seconds' => 90],
            ]);
        }, 3);
    }

    public function settle(TenantAiUsageEvent $event, int $actualSeconds, ?string $providerRequestId): TenantAiUsageEvent
    {
        return DB::transaction(function () use ($event, $actualSeconds, $providerRequestId): TenantAiUsageEvent {
            $event = TenantAiUsageEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status === 'settled') {
                return $event;
            }
            if ($event->status !== 'reserved') {
                throw new RuntimeException('This AI usage reservation is no longer active.');
            }
            $seconds = max(1, min(90, $actualSeconds));
            $providerRate = max(0, (int) config('services.openai.field_voice_provider_cost_micros_per_minute', 4500));
            $buyerRate = max($providerRate, (int) config('services.openai.field_voice_buyer_rate_micros_per_minute', 4500));
            $event->forceFill([
                'status' => 'settled', 'duration_seconds' => $seconds, 'provider_request_id' => $providerRequestId,
                'provider_cost_micros' => (int) ceil($seconds * $providerRate / 60),
                'buyer_charge_micros' => (int) ceil($seconds * $buyerRate / 60),
            ])->save();
            $usedMicros = (int) TenantAiUsageEvent::query()->forTenantId((int) $event->tenant_id)
                ->where('status', 'settled')->where('occurred_at', '>=', now()->startOfMonth())->sum('buyer_charge_micros');
            TenantBudSetting::query()->where('tenant_id', (int) $event->tenant_id)->update(['ai_used_cents' => (int) ceil($usedMicros / 10000)]);

            return $event;
        }, 3);
    }

    public function refund(TenantAiUsageEvent $event, string $reason): void
    {
        TenantAiUsageEvent::query()->whereKey($event->id)->where('status', 'reserved')->update([
            'status' => 'refunded', 'provider_cost_micros' => 0, 'buyer_charge_micros' => 0,
            'metadata' => [...(array) $event->metadata, 'refund_reason' => mb_substr($reason, 0, 120)], 'updated_at' => now(),
        ]);
    }
}
