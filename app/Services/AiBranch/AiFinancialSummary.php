<?php

namespace App\Services\AiBranch;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountingDateRangeService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** A narrow read of saved authoritative reports; never refreshes a provider. */
class AiFinancialSummary
{
    public function capture(Tenant $tenant, User $user, string $rangeKey = 'current_month'): array
    {
        app(AiAssistantAccess::class)->authorize($tenant, $user);
        abort_unless(config('ai_branch.assistant_finance_enabled'), 404);
        $modules = app(TenantModuleAccessResolver::class);
        $missing = ['available' => false, 'reason' => 'A permitted current QuickBooks report is not available.', 'source' => 'QuickBooks Online Profit and Loss', 'read_only' => true];
        if (! $modules->canAccess($tenant->id, 'accounting_command_center') || ! $modules->canAccess($tenant->id, 'quickbooks')) {
            return $missing;
        }
        $connection = IntegrationConnection::query()->where('tenant_id', $tenant->id)->where('provider', 'quickbooks')->where('status', 'connected')->latest('id')->first();
        if (! $connection) {
            return $missing;
        }
        $range = app(AccountingDateRangeService::class)->resolve($rangeKey, null, null, CarbonImmutable::now(app(AiWorkspaceContext::class)->timezone($tenant)));
        $snapshot = QuickBooksReportingSnapshot::query()->where('tenant_id', $tenant->id)->where('integration_connection_id', $connection->id)
            ->whereDate('period_start', $range['starts_at']->toDateString())->whereDate('period_end', $range['ends_at']->toDateString())->latest('observed_at')->first();
        if (! $snapshot || ! $snapshot->observed_at) {
            return $missing;
        }
        $metrics = (array) $snapshot->metrics;
        $amounts = [];
        foreach (['total_income', 'total_expenses', 'net_income'] as $key) {
            $value = $metrics[$key] ?? null;
            try {
                $amounts[$key] = is_numeric($value) ? BigDecimal::of((string) $value)->multipliedBy(100)->toScale(0)->toInt() : null;
                if ($amounts[$key] !== null && abs($amounts[$key]) > 9007199254740991) {
                    $amounts[$key] = null;
                }
            } catch (\Throwable) {
                $amounts[$key] = null;
            }
        }
        if ($amounts['total_income'] === null || $amounts['total_expenses'] === null) {
            return [...$missing, 'reason' => 'The saved report has missing or invalid totals.'];
        }
        $currency = $metrics['currency_code'] ?? null;

        return [
            'available' => true, 'source' => 'QuickBooks Online Profit and Loss', 'read_only' => true,
            'period_start' => $snapshot->period_start->toDateString(), 'period_end' => $snapshot->period_end->toDateString(),
            'basis' => in_array(strtolower((string) ($metrics['accounting_method'] ?? '')), ['cash', 'accrual'], true) ? strtolower($metrics['accounting_method']) : null,
            'currency' => is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
            'amounts_cents' => $amounts, 'observed_at' => $snapshot->observed_at->toIso8601String(),
            'stale' => $snapshot->observed_at->lt(now()->subHours(24)),
            'coverage' => 'Saved report totals only. Currency may be unrecorded; category/vendor changes and customer profitability are not established by these totals.',
            'url' => route('accounting.index', ['tenant' => $tenant->slug, 'range' => $rangeKey]),
        ];
    }
}
