<?php

namespace App\Console\Commands;

use App\Services\Marketing\CandleCashEarnedAnalyticsService;
use Illuminate\Console\Command;

class MarketingAuditCandleCashComposition extends Command
{
    protected $signature = 'marketing:audit-candle-cash-composition {--tenant-id= : Restrict the audit to a tenant id}';

    protected $description = 'Audit current Candle Cash liability composition and reconcile replayed ledger balances to candle_cash_balances.';

    public function handle(CandleCashEarnedAnalyticsService $analyticsService): int
    {
        $tenantId = $this->positiveInt($this->option('tenant-id'));
        $composition = $analyticsService->balanceLiability($tenantId);

        $this->line('tenant_id=' . ($tenantId !== null ? (string) $tenantId : 'null'));
        $this->line('total_current_balance=' . (string) data_get($composition, 'totalCurrentBalance.formattedAmount', '$0.00'));
        $this->line('legacy_migrated_remaining=' . (string) data_get($composition, 'legacyMigrated.formattedAmount', '$0.00'));
        $this->line('program_earned_remaining=' . (string) data_get($composition, 'programExpiring.formattedAmount', '$0.00'));
        $this->line('manual_nonexpiring_remaining=' . (string) data_get($composition, 'manualNonExpiring.formattedAmount', '$0.00'));
        $this->line('ledger_balance=' . (string) data_get($composition, 'ledgerBalance.formattedAmount', '$0.00'));
        $this->line('balance_table=' . (string) data_get($composition, 'balanceTable.formattedAmount', '$0.00'));
        $this->line('difference=' . (string) data_get($composition, 'difference.formattedAmount', '$0.00'));
        $this->line('reconciled=' . ((bool) ($composition['reconciled'] ?? false) ? 'yes' : 'no'));

        return (bool) ($composition['reconciled'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
