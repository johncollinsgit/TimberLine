<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Server-owned channel rules. An issued code retains its original value and channel. */
class CandleCashChannelPolicy
{
    public function rules(?int $tenantId): ?array
    {
        if (! $tenantId || ! ($slug = Tenant::query()->whereKey($tenantId)->value('slug'))) {
            return null;
        }

        $rules = config('marketing.candle_cash.tenant_channel_rules', [])[$slug] ?? null;

        return is_array($rules) ? $rules : null;
    }

    public function isInPersonCode(CandleCashRedemption $redemption): bool
    {
        return data_get($redemption->redemption_context, 'channel_policy.channel') === 'in_person';
    }

    public function issuedAmount(CandleCashRedemption $redemption): ?float
    {
        $policy = data_get($redemption->redemption_context, 'channel_policy');
        if (! is_array($policy)) {
            return null;
        }

        $tenantId = (int) $redemption->profile()->value('tenant_id');
        if (($policy['version'] ?? null) !== 1 || ($policy['tenant_id'] ?? null) !== $tenantId || $tenantId <= 0
            || ! in_array($policy['channel'] ?? null, ['online', 'in_person'], true)
            || ! is_int($policy['amount_cents'] ?? null) || $policy['amount_cents'] <= 0
            || $policy['amount_cents'] !== (int) $redemption->candle_cash_spent * 100
            || ($policy['channel'] === 'in_person' && $redemption->platform !== 'square')) {
            throw ValidationException::withMessages(['redemption' => 'The issued reward value or channel needs review.']);
        }

        return $policy['amount_cents'] / 100;
    }

    /** Both staff and Square ingestion use this lock and the same order namespace. No POS/provider mutation. */
    public function consumeInPerson(CandleCashRedemption $redemption, string $source, string $orderId, string $channel, array $context = []): CandleCashRedemption
    {
        $orderId = trim($orderId);

        return DB::transaction(function () use ($redemption, $source, $orderId, $channel, $context) {
            $tenantId = (int) $redemption->profile()->value('tenant_id');
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $current = CandleCashRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();
            $amount = $this->issuedAmount($current);
            $policy = data_get($current->redemption_context, 'channel_policy', []);
            if (! $this->isInPersonCode($current) || ! in_array($source, ['square_order', 'square_manual'], true) || $orderId === '' || strlen($orderId) > 120
                || $amount === null || $amount * 100 > ($policy['max_per_order_cents'] ?? 0)
                || ($policy['recurring_charges_allowed'] ?? null) !== false) {
                throw ValidationException::withMessages(['external_order_id' => 'Use this in-person reward once on a Square purchase and record its order ID. Recurring club charges are excluded.']);
            }
            if ($current->status === 'redeemed' && $current->external_order_id === $orderId && str_starts_with((string) $current->external_order_source, 'square')) {
                return $current;
            }
            if ($current->status !== 'issued' || ($current->expires_at && $current->expires_at->isPast())) {
                throw ValidationException::withMessages(['redemption' => 'This reward code is no longer available.']);
            }
            $used = CandleCashRedemption::query()->whereHas('profile', fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('status', 'redeemed')->where('external_order_id', $orderId)->where('external_order_source', 'like', 'square%')
                ->whereKeyNot($current->id)->exists();
            if ($used) {
                throw ValidationException::withMessages(['external_order_id' => 'This purchase already used Candle Cash. Only one reward is allowed per purchase.']);
            }
            $current->forceFill([
                'status' => 'redeemed', 'redeemed_channel' => $channel, 'external_order_source' => $source,
                'external_order_id' => $orderId, 'redeemed_at' => now(), 'redeemed_by' => $context['redeemed_by'] ?? null,
                'reconciliation_notes' => $context['notes'] ?? $current->reconciliation_notes,
            ])->save();
            app(MarketingStorefrontEventLogger::class)->log('redemption_reconciled', [
                'tenant_id' => $tenantId, 'marketing_profile_id' => $current->marketing_profile_id, 'candle_cash_redemption_id' => $current->id,
                'source_surface' => $channel === 'manual_reconcile' ? 'admin' : 'ingestion', 'endpoint' => $source,
                'source_type' => $source, 'source_id' => $orderId, 'dedupe_key' => 'in_person_reward:'.$current->id.':'.$orderId,
                'meta' => ['channel' => $channel, 'amount' => $amount, 'redeemed_by' => $context['redeemed_by'] ?? null],
            ]);

            return $current->fresh();
        });
    }
}
