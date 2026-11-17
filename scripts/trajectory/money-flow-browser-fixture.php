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
echo json_encode(['space_id' => $space->id, 'zero_id' => $zero->id, 'partial_id' => $partial->id, 'transfer_id' => $transfer->id,
    'tax_ids' => $taxIds, 'cash_outflow_ids' => [$repayment->id, $rent->id, $zero->id, $partial->id, ...$payrollIds, ...array_values($taxIds)]], JSON_THROW_ON_ERROR);
