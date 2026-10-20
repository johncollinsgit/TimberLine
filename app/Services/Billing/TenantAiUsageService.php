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
    /** Text features reserve against the same approved tenant cap as voice and Marketing. */
    public function reserveText(Tenant $tenant, User $user, string $requestId, string $feature, string $contextHash, string $model, array $rates, int $inputLimit = 32000, int $outputLimit = 1200): TenantAiUsageEvent
    {
        abort_unless(config('bud.ai_enabled') && config('bud.provider_configured') && \Illuminate\Support\Str::isUuid($requestId), 503, 'Paid AI is unavailable.');
        abort_unless($feature === 'teams_briefing' && strlen($contextHash) === 64 && $model !== '' && count($rates) === 2 && min($rates) > 0 && max($rates) <= 1000000000, 503, 'Verified AI model and rates are required.');

        return DB::transaction(function () use ($tenant, $user, $requestId, $feature, $contextHash, $model, $rates, $inputLimit, $outputLimit) {
            $setting = TenantBudSetting::where('tenant_id', $tenant->id)->lockForUpdate()->first();
            abort_unless($setting?->ai_status === 'approved', 403, 'Workspace AI and its spending cap require approval.');
            $existing = TenantAiUsageEvent::forTenantId($tenant->id)->where('client_uuid', $requestId)->first();
            if ($existing) {
                abort_unless($existing->feature === $feature && (int) $existing->user_id === (int) $user->id && ($existing->metadata['context_hash'] ?? '') === $contextHash, 409);

                return $existing;
            }
            $reservation = (int) ceil(($inputLimit * $rates[0] + $outputLimit * $rates[1]) / 1000000);
            $used = (int) TenantAiUsageEvent::forTenantId($tenant->id)->whereIn('status', ['reserved', 'settled'])->where('occurred_at', '>=', now('UTC')->startOfMonth())->sum('buyer_charge_micros');
            $cap = max(0, (int) $setting->ai_monthly_budget_cents) * 10000;
            abort_unless($cap > 0 && $used + $reservation <= $cap, 429, 'The tenant AI budget is exhausted. Deterministic reminders remain available.');

            return TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $requestId, 'feature' => $feature, 'context' => $feature, 'model' => $model, 'status' => 'reserved', 'duration_seconds' => 0, 'provider_cost_micros' => $reservation, 'buyer_charge_micros' => $reservation, 'occurred_at' => now('UTC'), 'metadata' => ['billing_scope' => 'tenant_monthly', 'pricing_version' => 'teams-text-v1', 'context_hash' => $contextHash, 'rates' => $rates, 'input_limit' => $inputLimit, 'output_limit' => $outputLimit]]);
        }, 3);
    }

    public function settleText(TenantAiUsageEvent $event, int $inputTokens, int $outputTokens, string $providerId): void
    {
        DB::transaction(function () use ($event, $inputTokens, $outputTokens, $providerId) {
            TenantBudSetting::where('tenant_id', $event->tenant_id)->lockForUpdate()->firstOrFail();
            $event = TenantAiUsageEvent::forTenantId($event->tenant_id)->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status === 'settled') {
                return;
            }
            $metadata = $event->metadata;
            abort_unless($event->status === 'reserved' && $event->feature === 'teams_briefing' && $inputTokens >= 0 && $outputTokens >= 0 && $inputTokens <= $metadata['input_limit'] && $outputTokens <= $metadata['output_limit'], 502);
            $cost = (int) ceil(($inputTokens * $metadata['rates'][0] + $outputTokens * $metadata['rates'][1]) / 1000000);
            $event->update(['status' => 'settled', 'provider_request_id' => mb_substr($providerId, 0, 120), 'provider_cost_micros' => $cost, 'buyer_charge_micros' => $cost, 'metadata' => [...$metadata, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]]);
            $used = (int) TenantAiUsageEvent::forTenantId($event->tenant_id)->where('status', 'settled')->where('occurred_at', '>=', now('UTC')->startOfMonth())->sum('buyer_charge_micros');
            TenantBudSetting::where('tenant_id', $event->tenant_id)->update(['ai_used_cents' => (int) ceil($used / 10000)]);
        }, 3);
    }

    public function reserveVoice(Tenant $tenant, User $user, string $clientUuid, string $context, string $model, int $seconds): TenantAiUsageEvent
    {
        abort_unless((bool) config('bud.ai_enabled') && (bool) config('bud.provider_configured'), 503, 'Paid AI is not available yet.');

        return DB::transaction(function () use ($tenant, $user, $clientUuid, $context, $model, $seconds): TenantAiUsageEvent {
            // Acquire the shared budget lock before any consistent read establishes a MySQL snapshot.
            $setting = TenantBudSetting::query()->where('tenant_id', (int) $tenant->id)->lockForUpdate()->first();
            $existing = TenantAiUsageEvent::query()->forTenantId((int) $tenant->id)->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                abort_unless($existing->feature === 'field_voice_transcription' && (int) $existing->user_id === (int) $user->id && $existing->context === $context, 409, 'This request belongs to a different AI action.');

                return $existing;
            }

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
