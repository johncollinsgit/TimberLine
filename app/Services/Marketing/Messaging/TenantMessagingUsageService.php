<?php

namespace App\Services\Marketing\Messaging;

use App\Models\Agreement;
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
            $includedAvailable = max(0, $period->included_units - $period->used_units - $period->reserved_units);
            $chargedUnits = max(0, $units - $includedAvailable);
            $rate = $this->buyerRate($tenantId, $channel);
            $amount = $chargedUnits * $rate;
            $providerCost = $units * $this->providerCost($provider, $channel);

            if ($amount > $credit->availableMicros() && ! $this->postpaidAuthorizedForChannel($tenantId, $channel)) {
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
                'pricing_version' => $this->pricingVersion($tenantId),
                'idempotency_key' => 'reserve:'.$idempotencyKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => ['charged_units' => $chargedUnits, 'rate_micros' => $rate, 'provider' => $provider],
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
                'pricing_version' => (string) config('marketing.messaging.platform.pricing_version'),
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

        return [
            'channel' => $channel,
            'included_units' => (int) ($period?->included_units ?? $this->includedUnits($tenantId, $channel)),
            'used_units' => (int) ($period?->used_units ?? 0),
            'reserved_units' => (int) ($period?->reserved_units ?? 0),
            'credit_balance_micros' => (int) ($credit?->balance_micros ?? 0),
            'credit_available_micros' => (int) ($credit?->availableMicros() ?? 0),
            'billing_mode' => $this->billingMode($tenantId),
            'overage_rate_micros' => $this->buyerRate($tenantId, $channel),
            'pricing_version' => $this->pricingVersion($tenantId),
        ];
    }

    public function hasUsageContract(int $tenantId): bool
    {
        return $this->activeUsageContract($tenantId) !== null;
    }

    public function postpaidAuthorized(int $tenantId): bool
    {
        if ($this->billingMode($tenantId) !== 'postpaid_invoice') {
            return false;
        }

        return $this->postpaidAgreement($tenantId) !== null;
    }

    public function postpaidAuthorizedForChannel(int $tenantId, string $channel): bool
    {
        return $this->postpaidAuthorized($tenantId)
            && is_numeric(data_get($this->activeUsageContract($tenantId)?->metadata, 'overage_rates_micros.'.strtolower(trim($channel))));
    }

    public function postpaidAgreement(int $tenantId): ?Agreement
    {
        $templateKey = trim((string) data_get($this->activeUsageContract($tenantId)?->metadata, 'agreement_template_key'));
        if (! in_array($templateKey, [
            Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES,
            Agreement::TEMPLATE_FRONT_YARD_CLIENT_SERVICES,
        ], true)) {
            return null;
        }

        return Agreement::withoutGlobalScopes()
            ->with(['acceptance', 'currentVersion'])
            ->where('tenant_id', $tenantId)
            ->where('template_key', $templateKey)
            ->whereIn('status', ['active', 'termination_pending'])
            ->whereNotNull('accepted_at')
            ->latest('id')
            ->first();
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
        $contract = $this->activeUsageContract($tenantId);
        $contractAllowance = data_get($contract?->metadata, 'included_units.'.$channel);
        if (is_numeric($contractAllowance)) {
            return max(0, (int) $contractAllowance);
        }

        $addons = TenantAccessAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('addon_key')
            ->all();

        if ($channel === 'email') {
            return in_array('bulk_email_marketing', $addons, true) ? 50000 : (in_array('messaging', $addons, true) ? 5000 : 0);
        }

        return $channel === 'sms' && in_array('sms', $addons, true) ? 1000 : 0;
    }

    protected function buyerRate(int $tenantId, string $channel): int
    {
        $contractRate = data_get($this->activeUsageContract($tenantId)?->metadata, 'overage_rates_micros.'.$channel);
        if (is_numeric($contractRate)) {
            return max(0, (int) $contractRate);
        }

        return (int) config("marketing.messaging.platform.buyer_rates_micros.{$channel}", 0);
    }

    protected function billingMode(int $tenantId): string
    {
        return strtolower(trim((string) data_get($this->activeUsageContract($tenantId)?->metadata, 'billing_mode', 'prepaid_credit')));
    }

    protected function pricingVersion(int $tenantId): string
    {
        return trim((string) data_get(
            $this->activeUsageContract($tenantId)?->metadata,
            'pricing_version',
            config('marketing.messaging.platform.pricing_version')
        ));
    }

    protected function activeUsageContract(int $tenantId): ?TenantAccessAddon
    {
        return TenantAccessAddon::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('addon_key', 'messaging_usage')
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first();
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
