<?php

// Reuse the isolated, authenticated fixture and its disposable-SQLite guard.
ob_start();
require __DIR__.'/money-flow-browser-fixture.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$connection = App\Models\IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fictional-pnl-company', 'status' => 'connected', 'access_token' => 'fictional-browser-only-access-token', 'expires_at' => now()->addDay()]);
App\Models\TenantModuleEntitlement::updateOrCreate(['tenant_id' => $tenant->id, 'module_key' => 'quickbooks'], ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'operator_setup', 'price_source' => 'catalog']);
App\Models\TenantModuleState::updateOrCreate(['tenant_id' => $tenant->id, 'module_key' => 'quickbooks'], ['enabled_override' => true, 'setup_status' => 'ready']);
App\Models\TenantModuleState::updateOrCreate(['tenant_id' => $tenant->id, 'module_key' => 'integrations'], ['enabled_override' => true, 'setup_status' => 'ready']);
app()->forgetInstance(App\Services\Tenancy\TenantModuleAccessResolver::class);
$accountIds = array_flip(['sales', 'credits', 'products', 'wax', 'vessels', 'payroll', 'owner', 'studio', 'event', 'rent', 'interest']);
$account = fn ($key, $label, $amount, $children = []) => ['key' => $key, 'id' => (string) (300 + $accountIds[$key]), 'label' => $label, 'amount' => $amount, 'children' => $children];
$save = function ($from, $through, $basis, $income, $cogs, $expenses, $other = 100) use ($tenant, $connection, $account) {
    $parts = ['Income' => [$account('sales', 'Candle sales', $income + 500), $account('credits', 'Discounts & returns', -500)],
        'COGS' => [$account('products', 'Product costs', $cogs, [$account('wax', 'Wax & fragrance', $cogs - 500), $account('vessels', 'Vessels & packaging', 500)])],
        'Expenses' => [$account('payroll', 'Payroll', 1200, [$account('owner', 'Owner payroll', 300), $account('studio', 'Studio payroll', 600), $account('event', 'Event payroll', 300)]), $account('rent', 'Studio rent', $expenses - 1200)],
        'OtherIncome' => [], 'OtherExpenses' => [$account('interest', 'Interest expense', $other)]];

    return App\Models\QuickBooksReportingSnapshot::create(['tenant_id' => $tenant->id, 'integration_connection_id' => $connection->id,
        'range_key' => 'fictional:pnl:'.$basis.':'.$from->toDateString().':'.$through->toDateString(), 'period_start' => $from->toDateString(), 'period_end' => $through->toDateString(), 'observed_at' => now(),
        'metrics' => ['accounting_method' => ucfirst($basis), 'total_income' => $income, 'total_cogs' => $cogs, 'total_expenses' => $expenses, 'total_other_income' => 0, 'total_other_expenses' => $other, 'net_operating_income' => $income - $cogs - $expenses, 'net_income' => $income - $cogs - $expenses - $other,
            'source_report' => ['ReportName' => 'ProfitAndLoss', 'ReportBasis' => ucfirst($basis), 'StartPeriod' => $from->toDateString(), 'EndPeriod' => $through->toDateString(), 'Currency' => 'USD', 'SummarizeColumnsBy' => 'Total'],
            'account_sections' => collect($parts)->map(fn ($rows, $group) => ['group' => $group, 'children' => $rows])->values()->all()]]);
};
foreach (['owner_payroll' => -12345, 'studio_payroll' => -23456, 'event_payroll' => -34567] as $role => $amount) {
    $post($companyCash, 'pnl-'.$role, 'Fictional '.$role.' net pay', $amount, 'expense', $role);
}
$through = Carbon\CarbonImmutable::parse($today->toDateString());
$from = $through->startOfYear();
$save($from, $through, 'accrual', 50000, 20000, 15000);
$save($from, $through, 'cash', 45000, 18000, 14000);
$save($from->subYearNoOverflow(), $through->subYearNoOverflow(), 'accrual', 40000, 18000, 13000);
$previousEnd = $from->subDay();
$save($previousEnd->subDays((int) $from->diffInDays($through)), $previousEnd, 'accrual', 38000, 18000, 14000);
for ($month = $from; $month->lte($through); $month = $month->addMonth()) {
    $end = $month->endOfMonth()->startOfDay()->min($through);
    // A deliberate historical gap must remain a gap in charts and sparklines.
    if ($month->month !== 3 && ! ($month->equalTo($from) && $end->equalTo($through))) {
        $save($month, $end, 'accrual', 5000 + $month->month * 100, 2000, 1800);
    }
}
$fixture['pnl_year_start'] = $from->toDateString();
$fixture['pnl_net'] = '$14,900.00';
echo json_encode($fixture, JSON_THROW_ON_ERROR);
