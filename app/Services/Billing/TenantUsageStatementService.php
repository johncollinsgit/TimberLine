<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantMessagingCreditAccount;
use App\Models\TenantMessagingLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/** Read-only, tenant-scoped actuals. A statement never creates another charge. */
class TenantUsageStatementService
{
    public function snapshot(Tenant $tenant, ?CarbonInterface $start = null, ?CarbonInterface $end = null): array
    {
        $start ??= now()->startOfMonth();
        $end ??= now();
        if ($end->lessThan($start)) {
            throw new \InvalidArgumentException('Usage period ends before it starts.');
        }
        $base = ['version' => 1, 'tenant_id' => (int) $tenant->id, 'currency' => 'USD',
            'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String(),
            'captured_at' => now()->toIso8601String(), 'informational_only' => true];
        if (! Schema::hasTable('tenant_messaging_ledger_entries') || ! Schema::hasTable('tenant_messaging_credit_accounts')) {
            return [...$base, 'available' => false];
        }
        $query = TenantMessagingLedgerEntry::query()->forAllTenants()->where('tenant_id', $tenant->id)
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<=', $end);
        $rows = (clone $query)->where('entry_type', 'usage_settlement')->where('status', 'settled')
            ->select(['channel', 'unit_type', 'pricing_version'])
            ->selectRaw('SUM(units) AS units, SUM(amount_micros) AS amount_micros')
            ->groupBy('channel', 'unit_type', 'pricing_version')->orderBy('channel')->get()
            ->map(fn ($row): array => ['service' => $row->channel, 'unit' => $row->unit_type,
                'quantity' => (int) $row->units, 'amount_micros' => (int) $row->amount_micros,
                'effective_rate_micros' => $row->units ? (int) round($row->amount_micros / $row->units) : 0,
                'pricing_version' => $row->pricing_version])->all();
        $account = TenantMessagingCreditAccount::query()->forAllTenants()->where('tenant_id', $tenant->id)->first();
        $ai = Schema::hasTable('tenant_ai_usage_events') ? TenantAiUsageEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('status', 'settled')->where('buyer_charge_micros', '>', 0)
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<=', $end)
            ->select(['feature', 'model'])->selectRaw('COUNT(*) AS requests, SUM(buyer_charge_micros) AS amount_micros')
            ->groupBy('feature', 'model')->orderBy('feature')->get()
            ->map(fn ($row): array => ['feature' => $row->feature, 'model' => $row->model,
                'requests' => (int) $row->requests, 'amount_micros' => (int) $row->amount_micros])->all() : [];

        return [...$base, 'available' => true, 'wallet_usage' => $rows,
            'wallet_usage_micros' => array_sum(array_column($rows, 'amount_micros')),
            'credit_purchases_micros' => (int) (clone $query)->where('entry_type', 'credit_funding')->sum('amount_micros'),
            'current_balance_micros' => (int) ($account?->balance_micros ?? 0),
            'current_reserved_micros' => (int) ($account?->reserved_micros ?? 0),
            'current_available_micros' => (int) ($account?->availableMicros() ?? 0),
            'recorded_ai_usage' => $ai,
            'note' => 'Wallet deductions, separate credit purchases and recorded AI activity are distinct. This statement does not add charges to a service invoice. AI follows its accepted billing scope; planned features are not activated. Effective messaging rates include any contracted allowance. Current balance is as of capture, not the historical period end.',
        ];
    }
}
