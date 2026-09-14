<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.sqlite.database') !== '/tmp/trajectory-preview.sqlite') {
    exit(1);
}
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Snapshot;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;

$user = User::firstOrCreate(['email' => 'owner@trajectory.test'], ['name' => 'Demo Owner', 'password' => Illuminate\Support\Facades\Hash::make('Trajectory-local-preview-2026!'), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
$user->forceFill(['email_verified_at' => now()])->save();
[$space] = app(PilotService::class)->prepare($user, null, true);
$space->update(['name' => 'Fictional household · Demo']);
$account = Account::firstOrCreate(['source_key' => 'demo:checking'], ['space_id' => $space->id, 'name' => 'Demo checking', 'kind' => 'cash', 'balance_cents' => 1825000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
$rows = [];
for ($i = 89; $i >= 0; $i--) {
    $d = now()->subDays($i);
    $rows[] = ['id' => 'food-'.$i, 'date' => $d->toDateString(), 'merchant' => 'Neighborhood Market', 'amount_cents' => -(2200 + ($i * 137) % 4300), 'category' => 'groceries'];
    if ($i % 5 === 0) {
        $rows[] = ['id' => 'fun-'.$i, 'date' => $d->toDateString(), 'merchant' => 'Weekend Finds', 'amount_cents' => -(3200 + ($i * 111) % 7000), 'category' => 'shopping'];
    }if ($d->day === 1 || $d->day === 15) {
        $rows[] = ['id' => 'pay-'.$i, 'date' => $d->toDateString(), 'merchant' => 'Demo company pay', 'amount_cents' => 320000, 'category' => 'income'];
    }
}
app(LedgerService::class)->ingest($account, $rows);
$fixtures = [
    ['recurring', 'Mortgage', ['account_id' => $account->id, 'merchant' => 'Mortgage lender', 'amount_cents' => -185000, 'next_due_on' => now()->addMonth()->startOfMonth()->toDateString(), 'cadence' => 'monthly', 'category' => 'housing', 'expense_type' => 'fixed', 'confirmed' => true]],
    ['recurring', 'Electricity', ['account_id' => $account->id, 'merchant' => 'Power company', 'amount_cents' => -19500, 'next_due_on' => now()->addDays(4)->toDateString(), 'cadence' => 'monthly', 'category' => 'utilities', 'expense_type' => 'essential_variable', 'confirmed' => true]],
    ['recurring', 'Internet', ['account_id' => $account->id, 'merchant' => 'Internet provider', 'amount_cents' => -8500, 'next_due_on' => now()->addDays(8)->toDateString(), 'cadence' => 'monthly', 'category' => 'utilities', 'expense_type' => 'fixed', 'confirmed' => true]],
    ['goal', 'Six-month emergency fund', ['target_cents' => 2400000, 'saved_cents' => 850000, 'monthly_cents' => 60000, 'target_on' => now()->addYears(2)->toDateString(), 'priority' => 1, 'goal_type' => 'emergency']],
    ['goal', 'Family vacation', ['target_cents' => 500000, 'saved_cents' => 160000, 'monthly_cents' => 30000, 'target_on' => now()->addYear()->toDateString(), 'priority' => 2, 'goal_type' => 'savings']],
    ['asset', 'Our home', ['value_cents' => 42500000, 'asset_type' => 'property', 'observed_on' => now()->toDateString()]],
    ['scenario', 'Spend 20% less', ['spending_reduction_bps' => 2000, 'additional_monthly_income_cents' => 0, 'extra_debt_payment_cents' => 0]],
    ['metal', 'Gold coins', ['metal' => 'gold', 'quantity' => '3', 'weight' => '1', 'unit' => 'troy_ounce', 'purity_bps' => 9999, 'cost_basis_cents' => 650000, 'acquired_on' => '2024-01-01', 'resale_adjustment_cents' => 0]],
];
foreach ($fixtures as [$kind,$name,$data]) {
    Record::updateOrCreate(['space_id' => $space->id, 'kind' => $kind, 'name' => $name], ['data' => $data]);
}
for ($i = 30; $i >= 0; $i--) {
    Snapshot::updateOrCreate(['space_id' => $space->id, 'observed_on' => now()->subDays($i)->toDateString()], ['data' => ['net_worth_cents' => 43500000 + (30 - $i) * 5000, 'cash_cents' => 1600000 + (30 - $i) * 7000, 'complete' => false]]);
}
echo "Local fictional preview ready.\n";
