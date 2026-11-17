<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), '/codex-money-flow-')) {
    throw new RuntimeException('Disposable money-flow browser database only.');
}
$owner = App\Models\User::factory()->create(['name' => 'Fictional Flow Owner', 'email' => 'flow@fixture.invalid', 'email_verified_at' => now(), 'is_active' => true, 'password' => Illuminate\Support\Facades\Hash::make('Fictional-flow-only-2028!')]);
[$space] = app(App\Services\Trajectory\PilotService::class)->prepare($owner, null, true);
$cash = App\Models\Trajectory\Account::create(['space_id' => $space->id, 'source_key' => 'fictional:flow-cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'balance_cents' => 500000, 'observed_at' => now(), 'history_start' => now()->subMonths(6)]);
$card = App\Models\Trajectory\Account::create(['space_id' => $space->id, 'source_key' => 'fictional:flow-card', 'name' => 'Fictional card', 'kind' => 'credit', 'balance_cents' => 100000, 'observed_at' => now()]);
$today = now($space->timezone)->startOfDay();
$posted = $today->copy()->subDay();
if ($posted->year !== $today->year) {
    $posted = $today;
}
$post = function ($account, $key, $merchant, $amount, $flow, $category, $reviewed = true) use ($posted) {
    app(App\Services\Trajectory\LedgerService::class)->ingest($account, [['id' => $key, 'date' => $posted->toDateString(), 'merchant' => $merchant, 'amount_cents' => $amount]]);
    $tx = App\Models\Trajectory\Transaction::where('source_key', $account->source_key.':'.$key)->firstOrFail();
    $tx->update(['flow' => $flow, 'category' => $category, 'reviewed' => $reviewed]);

    return $tx;
};
$post($cash, 'income', 'Fictional employer', 200000, 'income', 'income');
$post($cash, 'draw', 'Fictional lender', 50000, 'loan_draw', 'income');
$tenant = App\Models\Tenant::create(['slug' => 'fictional-flow-company', 'name' => 'Fictional Candle Studio']);
App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
$owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
[$business] = app(App\Services\Trajectory\PilotService::class)->prepare($owner, $tenant, true, 'business');
$business->update(['name' => 'Fictional Candle Studio']);
app()->forgetInstance(App\Services\Tenancy\TenantModuleAccessResolver::class);
Illuminate\Support\Facades\DB::table('trajectory_links')->insert(['household_id' => $space->id, 'business_id' => $business->id, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
foreach ([['category', 'company_receipts', 'other_support'], ['merchant', 'Fictional net payroll', 'net_payroll'], ['merchant', 'Fictional owner draw', 'owner_draw']] as [$type, $value, $role]) {
    App\Models\Trajectory\Record::create(['space_id' => $space->id, 'kind' => 'company_support_source', 'name' => 'Fictional receipt mapping', 'active' => true, 'data' => ['business_space_id' => $business->id, 'source_type' => $type, 'source_value' => $value, 'support_kind' => $role, 'confirmed' => true]]);
}
$familyIds = [];
foreach ([['company-pay', 'Fictional net payroll', 11001, 'income', 'company_receipts'], ['company-draw', 'Fictional owner draw', 22002, 'owner_distribution', 'company_receipts'], ['company-other', 'Fictional other support', 3003, 'owner_distribution', 'company_receipts'], ['metals', 'Fictional metal sale', 35000, 'asset_sale', 'gold_and_silver'], ['odd-jobs', 'Fictional tech work', 4500, 'income', 'odd_jobs']] as [$key, $merchant, $amount, $flow, $category]) {
    $familyIds[$key] = $post($cash, $key, $merchant, $amount, $flow, $category)->id;
}
$otherCash = App\Models\Trajectory\Account::create(['space_id' => $space->id, 'source_key' => 'fictional:other-bank', 'name' => 'Fictional second bank', 'kind' => 'cash', 'balance_cents' => 1000]);
$companyCash = App\Models\Trajectory\Account::create(['space_id' => $business->id, 'source_key' => 'fictional:company-bank', 'name' => 'Fictional company bank', 'kind' => 'cash', 'balance_cents' => 1000]);
$post($companyCash, 'company-only', 'Fictional company-only vendor', -100, 'expense', 'supplies');
$post($cash, 'medical', 'Fictional medical shares', 40000, 'medical_share', 'health');
$post($cash, 'unknown', 'Fictional unknown deposit', 3000, 'unclassified_deposit', 'uncategorized', false);
$post($card, 'purchase', 'Fictional card purchase', -20000, 'expense', 'groceries');
$repayment = $post($cash, 'repayment', 'Fictional card repayment', -10000, 'card_payment', 'uncategorized');
$post($card, 'repayment', 'Fictional bank repayment', 10000, 'card_payment', 'uncategorized');
$rent = $post($cash, 'rent', 'Fictional landlord', -90000, 'expense', 'housing');
$payrollIds = [];
foreach (['owner_payroll' => -12001, 'studio_payroll' => -23002, 'event_payroll' => -34003, 'payroll' => -4555] as $category => $amount) {
    $payrollIds[] = $post($cash, $category, 'Fictional '.$category.' withdrawal', $amount, 'expense', $category)->id;
}
$zero = $post($cash, 'shared-cost', 'Fictional shared cost', -77000, 'expense', 'shopping');
App\Models\Trajectory\Allocation::where('transaction_id', $zero->id)->update(['amount_cents' => 0]);
$partial = $post($cash, 'partial-cost', 'Fictional partial cost', -10000, 'expense', 'transport');
App\Models\Trajectory\Allocation::where('transaction_id', $partial->id)->update(['amount_cents' => -2500]);
$transfer = $post($cash, 'internal-transfer', 'Fictional internal transfer', -12345, 'transfer', 'uncategorized');
App\Models\Trajectory\Allocation::where('transaction_id', $transfer->id)->update(['amount_cents' => 0]);
$taxIds = [];
foreach (['known' => [-10000, [9000, 200, 700, 100]], 'unknown' => [-22222, null]] as $key => [$amount, $parts]) {
    $tax = $post($cash, 'tax-'.$key, 'Fictional '.$key.' sales-tax cash', $amount, 'expense', 'uncategorized', false);
    $input = ['transaction_id' => $tax->id, 'paid_on' => $posted->toDateString(), 'amount_cents' => $amount,
        'agency' => 'Fictional revenue agency', 'tax_type' => 'sales_tax', 'movement' => 'payment', 'source' => 'Fictional browser tax receipt', 'reviewed' => true,
        'expected_bank_source_fingerprint' => app(App\Services\Trajectory\SalesTaxService::class)->bankFingerprint($space->id, $cash->id, $tax->id, $posted->toDateString(), $amount, $tax->merchant)];
    if ($parts !== null) {
        $input = [...$input, ...array_combine(['tax_principal_cents', 'interest_cents', 'penalty_cents', 'other_fees_cents'], $parts)];
    }
    app(App\Services\Trajectory\RecordService::class)->save($owner, $space, 'tax_remittance', 'Fictional '.$key.' tax evidence', $input);
    app(App\Services\Trajectory\LedgerService::class)->classify($owner, $space, $tax, ['version' => $tax->version, 'flow' => 'sales_tax_remittance', 'category' => 'taxes', 'face_punched' => false, 'bullshit_spending' => false]);
    $taxIds[$key] = $tax->id;
}
$cashInflowIds = App\Models\Trajectory\Transaction::where('account_id', $cash->id)->where('amount_cents', '>', 0)->where('flow', '!=', 'transfer')->pluck('id')->all();
echo json_encode(['posted_on' => $posted->toDateString(), 'today' => $today->toDateString(), 'cash_inflow_ids' => $cashInflowIds, 'space_id' => $space->id, 'company_id' => $business->id, 'family_ids' => $familyIds, 'cash_id' => $cash->id, 'other_cash_id' => $otherCash->id, 'zero_id' => $zero->id, 'partial_id' => $partial->id, 'transfer_id' => $transfer->id,
    'tax_ids' => $taxIds, 'cash_outflow_ids' => [$repayment->id, $rent->id, $zero->id, $partial->id, ...$payrollIds, ...array_values($taxIds)]], JSON_THROW_ON_ERROR);
