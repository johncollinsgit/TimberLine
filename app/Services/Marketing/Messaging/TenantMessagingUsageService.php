<?php

namespace App\Services\Marketing\Messaging;

use App\Models\TenantAccessAddon;
use App\Models\TenantMessagingCreditAccount;
use App\Models\TenantMessagingLedgerEntry;
use App\Models\TenantMessagingUsagePeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantMessagingUsageService
{
    /**
     * @return array{ledger_id:int,idempotency_key:string,units:int,amount_micros:int,provider_cost_micros:int,channel:string}
     */
    public function reserve(
        int $tenantId,
        string $channel,
        int $units,
        string $idempotencyKey,
        string $provider,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): array {
        $channel = strtolower(trim($channel));
        $units = max(1, $units);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new RuntimeException('A messaging reservation idempotency key is required.');
        }

        return DB::transaction(function () use ($tenantId, $channel, $units, $idempotencyKey, $provider, $sourceType, $sourceId): array {
            $existing = TenantMessagingLedgerEntry::query()
                ->forAllTenants()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', 'reserve:'.$idempotencyKey)
                ->first();
            if ($existing) {
                return $this->reservationPayload($existing);
            }

            $period = $this->lockedPeriod($tenantId, $channel);
            $credit = $this->lockedCreditAccount($tenantId);
            $existing = TenantMessagingLedgerEntry::query()
                ->forAllTenants()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', 'reserve:'.$idempotencyKey)
                ->first();
            if ($existing) {
                return $this->reservationPayload($existing);
            }
            $pricing = $this->rateCard($channel);
            $usageBefore = $period->used_units + $period->reserved_units;
            $usageAfter = $usageBefore + $units;
            $overageBefore = max(0, $usageBefore - $period->included_units);
            $overageAfter = max(0, $usageAfter - $period->included_units);
            $blocksBefore = $this->blocksRequired($overageBefore, $pricing['overage_block_units']);
            $blocksAfter = $this->blocksRequired($overageAfter, $pricing['overage_block_units']);
            $chargedBlocks = max(0, $blocksAfter - $blocksBefore);
            $chargedUnits = max(0, $overageAfter - $overageBefore);
            $amount = $chargedBlocks * $pricing['overage_block_price_micros'];
            $providerCost = $units * $this->providerCost($provider, $channel);

            if ($amount > $credit->availableMicros()) {
                throw new RuntimeException('Messaging credit is too low for this send. Add prepaid credit and try again.');
            }

            $period->increment('reserved_units', $units);
            $credit->increment('reserved_micros', $amount);

            $entry = TenantMessagingLedgerEntry::query()->forAllTenants()->create([
                'tenant_id' => $tenantId,
                'tenant_messaging_credit_account_id' => $credit->id,
                'tenant_messaging_usage_period_id' => $period->id,
                'entry_type' => 'usage_reservation',
                'status' => 'reserved',
                'channel' => $channel,
                'unit_type' => $channel === 'email' ? 'email' : ($channel === 'mms' ? 'message' : 'segment'),
                'units' => $units,
                'amount_micros' => $amount,
                'provider_cost_micros' => $providerCost,
                'pricing_version' => $this->pricingVersion(),
                'idempotency_key' => 'reserve:'.$idempotencyKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => [
                    'charged_units' => $chargedUnits,
                    'charged_blocks' => $chargedBlocks,
                    'overage_block_units' => $pricing['overage_block_units'],
                    'overage_block_price_micros' => $pricing['overage_block_price_micros'],
                    'provider' => $provider,
                ],
                'occurred_at' => now(),
            ]);

            return $this->reservationPayload($entry);
        }, 3);
    }

    public function settle(int $tenantId, string $idempotencyKey, array $metadata = []): TenantMessagingLedgerEntry
    {
        return DB::transaction(function () use ($tenantId, $idempotencyKey, $metadata): TenantMessagingLedgerEntry {
            $settled = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'settle:'.$idempotencyKey)->first();
            if ($settled) {
                return $settled;
            }

            $reservation = $this->lockedReservation($tenantId, $idempotencyKey);
            $settled = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'settle:'.$idempotencyKey)->first();
            if ($settled) {
                return $settled;
            }
            $refunded = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'refund:'.$idempotencyKey)->exists();
            if ($refunded) {
                throw new RuntimeException('A refunded messaging reservation cannot be settled.');
            }
            $period = TenantMessagingUsagePeriod::query()->forAllTenants()->whereKey($reservation->tenant_messaging_usage_period_id)->lockForUpdate()->firstOrFail();
            $credit = TenantMessagingCreditAccount::query()->forAllTenants()->whereKey($reservation->tenant_messaging_credit_account_id)->lockForUpdate()->firstOrFail();

            $period->update([
                'reserved_units' => max(0, $period->reserved_units - $reservation->units),
                'used_units' => $period->used_units + $reservation->units,
                'provider_cost_micros' => $period->provider_cost_micros + $reservation->provider_cost_micros,
                'buyer_charge_micros' => $period->buyer_charge_micros + $reservation->amount_micros,
            ]);
            $credit->update([
                'reserved_micros' => max(0, $credit->reserved_micros - $reservation->amount_micros),
                'balance_micros' => max(0, $credit->balance_micros - $reservation->amount_micros),
            ]);

            return TenantMessagingLedgerEntry::query()->forAllTenants()->create([
                ...$reservation->only([
                    'tenant_id', 'tenant_messaging_credit_account_id', 'tenant_messaging_usage_period_id',
                    'channel', 'unit_type', 'units', 'amount_micros', 'provider_cost_micros',
                    'pricing_version', 'source_type', 'source_id',
                ]),
                'entry_type' => 'usage_settlement',
                'status' => 'settled',
                'idempotency_key' => 'settle:'.$idempotencyKey,
                'metadata' => [...(array) $reservation->metadata, ...$metadata],
                'occurred_at' => now(),
            ]);
        }, 3);
    }

    public function refund(int $tenantId, string $idempotencyKey, ?string $reason = null): TenantMessagingLedgerEntry
    {
        return DB::transaction(function () use ($tenantId, $idempotencyKey, $reason): TenantMessagingLedgerEntry {
            $refunded = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'refund:'.$idempotencyKey)->first();
            if ($refunded) {
                return $refunded;
            }

            $reservation = $this->lockedReservation($tenantId, $idempotencyKey);
            $refunded = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'refund:'.$idempotencyKey)->first();
            if ($refunded) {
                return $refunded;
            }
            if (TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'settle:'.$idempotencyKey)->exists()) {
                throw new RuntimeException('A settled messaging reservation cannot be refunded.');
            }
            $period = TenantMessagingUsagePeriod::query()->forAllTenants()->whereKey($reservation->tenant_messaging_usage_period_id)->lockForUpdate()->firstOrFail();
            $credit = TenantMessagingCreditAccount::query()->forAllTenants()->whereKey($reservation->tenant_messaging_credit_account_id)->lockForUpdate()->firstOrFail();
            $period->update(['reserved_units' => max(0, $period->reserved_units - $reservation->units)]);
            $credit->update(['reserved_micros' => max(0, $credit->reserved_micros - $reservation->amount_micros)]);

            return TenantMessagingLedgerEntry::query()->forAllTenants()->create([
                ...$reservation->only([
                    'tenant_id', 'tenant_messaging_credit_account_id', 'tenant_messaging_usage_period_id',
                    'channel', 'unit_type', 'units', 'amount_micros', 'provider_cost_micros',
                    'pricing_version', 'source_type', 'source_id',
                ]),
                'entry_type' => 'usage_refund',
                'status' => 'refunded',
                'amount_micros' => -$reservation->amount_micros,
                'provider_cost_micros' => 0,
                'idempotency_key' => 'refund:'.$idempotencyKey,
                'metadata' => ['reason' => $reason],
                'occurred_at' => now(),
            ]);
        }, 3);
    }

    public function fund(int $tenantId, int $amountMicros, string $idempotencyKey, array $metadata = []): TenantMessagingLedgerEntry
    {
        if ($amountMicros <= 0) {
            throw new RuntimeException('Credit funding amount must be positive.');
        }

        return DB::transaction(function () use ($tenantId, $amountMicros, $idempotencyKey, $metadata): TenantMessagingLedgerEntry {
            $existing = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'fund:'.$idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $credit = $this->lockedCreditAccount($tenantId);
            $existing = TenantMessagingLedgerEntry::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('idempotency_key', 'fund:'.$idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
            $credit->update([
                'balance_micros' => $credit->balance_micros + $amountMicros,
                'last_funded_at' => now(),
            ]);

            return TenantMessagingLedgerEntry::query()->forAllTenants()->create([
                'tenant_id' => $tenantId,
                'tenant_messaging_credit_account_id' => $credit->id,
                'entry_type' => 'credit_funding',
                'status' => 'settled',
                'units' => 0,
                'amount_micros' => $amountMicros,
                'provider_cost_micros' => 0,
                'pricing_version' => $this->pricingVersion(),
                'idempotency_key' => 'fund:'.$idempotencyKey,
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);
        }, 3);
    }

    /** @return array<string,int|string> */
    public function summary(int $tenantId, string $channel): array
    {
        $period = $this->periodQuery($tenantId, $channel)->first();
        $credit = TenantMessagingCreditAccount::query()->forAllTenants()->where('tenant_id', $tenantId)->first();

        $pricing = $this->rateCard($channel);
        $package = $this->packageForChannel($tenantId, $channel);
        $includedUnits = (int) ($period?->included_units ?? $this->includedUnits($tenantId, $channel));
        $consumedUnits = (int) (($period?->used_units ?? 0) + ($period?->reserved_units ?? 0));

        return [
            'channel' => $channel,
            'included_units' => $includedUnits,
            'used_units' => (int) ($period?->used_units ?? 0),
            'reserved_units' => (int) ($period?->reserved_units ?? 0),
            'overage_units' => max(0, $consumedUnits - $includedUnits),
            'overage_blocks' => $this->blocksRequired(max(0, $consumedUnits - $includedUnits), $pricing['overage_block_units']),
            'overage_block_units' => $pricing['overage_block_units'],
            'overage_block_price_micros' => $pricing['overage_block_price_micros'],
            'package_key' => (string) ($package['key'] ?? ''),
            'monthly_price_cents' => (int) ($package['monthly_price_cents'] ?? 0),
            'credit_balance_micros' => (int) ($credit?->balance_micros ?? 0),
            'credit_available_micros' => (int) ($credit?->availableMicros() ?? 0),
            'pricing_version' => $this->pricingVersion(),
        ];
    }

    public function settledEntry(int $tenantId, string $idempotencyKey): ?TenantMessagingLedgerEntry
    {
        return TenantMessagingLedgerEntry::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', 'settle:'.$idempotencyKey)
            ->first();
    }

    protected function lockedPeriod(int $tenantId, string $channel): TenantMessagingUsagePeriod
    {
        $period = $this->periodQuery($tenantId, $channel)->lockForUpdate()->first();
        if ($period) {
            $period->update(['included_units' => $this->includedUnits($tenantId, $channel)]);

            return $period->fresh();
        }

        DB::table('tenant_messaging_usage_periods')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'included_units' => $this->includedUnits($tenantId, $channel),
            'used_units' => 0,
            'reserved_units' => 0,
            'provider_cost_micros' => 0,
            'buyer_charge_micros' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->periodQuery($tenantId, $channel)->lockForUpdate()->firstOrFail();
    }

    protected function periodQuery(int $tenantId, string $channel)
    {
        return TenantMessagingUsagePeriod::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('period_start', now()->startOfMonth()->toDateString());
    }

    protected function lockedCreditAccount(int $tenantId): TenantMessagingCreditAccount
    {
        $credit = TenantMessagingCreditAccount::query()->forAllTenants()->where('tenant_id', $tenantId)->lockForUpdate()->first();

        if ($credit) {
            return $credit;
        }

        DB::table('tenant_messaging_credit_accounts')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'currency' => 'USD',
            'balance_micros' => 0,
            'reserved_micros' => 0,
            'low_balance_threshold_micros' => 5000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return TenantMessagingCreditAccount::query()->forAllTenants()
            ->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
    }

    protected function lockedReservation(int $tenantId, string $idempotencyKey): TenantMessagingLedgerEntry
    {
        $reservation = TenantMessagingLedgerEntry::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', 'reserve:'.$idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $reservation) {
            throw new RuntimeException('Messaging usage reservation was not found.');
        }

        return $reservation;
    }

    protected function includedUnits(int $tenantId, string $channel): int
    {
        return (int) ($this->packageForChannel($tenantId, $channel)['included_units'] ?? 0);
    }

    /** @return array{key:string,included_units:int,monthly_price_cents:int}|null */
    protected function packageForChannel(int $tenantId, string $channel): ?array
    {
        $addons = TenantAccessAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('addon_key')
            ->all();

        return collect($addons)
            ->map(function (string $addon) use ($channel): array {
                return [
                    'key' => $addon,
                    'included_units' => max(0, (int) config("module_catalog.addons.{$addon}.pricing.usage.{$channel}.included_units", 0)),
                    'monthly_price_cents' => max(0, (int) config("module_catalog.addons.{$addon}.pricing.recurring_price_cents", 0)),
                ];
            })
            ->filter(fn (array $package): bool => $package['included_units'] > 0)
            ->sortByDesc('included_units')
            ->first();
    }

    /** @return array{overage_block_units:int,overage_block_price_micros:int} */
    protected function rateCard(string $channel): array
    {
        $units = max(1, (int) config("module_catalog.messaging_usage.channels.{$channel}.overage_block_units", 1));
        $price = max(0, (int) config("module_catalog.messaging_usage.channels.{$channel}.overage_block_price_micros", 0));

        return ['overage_block_units' => $units, 'overage_block_price_micros' => $price];
    }

    protected function blocksRequired(int $units, int $blockUnits): int
    {
        return $units <= 0 ? 0 : (int) ceil($units / max(1, $blockUnits));
    }

    protected function pricingVersion(): string
    {
        return (string) config('module_catalog.messaging_usage.pricing_version', 'unversioned');
    }

    protected function providerCost(string $provider, string $channel): int
    {
        $provider = $provider === 'twilio_subaccount' ? 'twilio' : $provider;

        return (int) config("marketing.messaging.platform.provider_cost_micros.{$provider}.{$channel}", 0);
    }

    /** @return array{ledger_id:int,idempotency_key:string,units:int,amount_micros:int,provider_cost_micros:int,channel:string} */
    protected function reservationPayload(TenantMessagingLedgerEntry $entry): array
    {
        return [
            'ledger_id' => (int) $entry->id,
            'idempotency_key' => (string) $entry->idempotency_key,
            'units' => (int) $entry->units,
            'amount_micros' => (int) $entry->amount_micros,
            'provider_cost_micros' => (int) $entry->provider_cost_micros,
            'channel' => (string) $entry->channel,
        ];
    }
}
