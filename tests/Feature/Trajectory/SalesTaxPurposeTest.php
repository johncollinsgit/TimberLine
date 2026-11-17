<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\EconomicActivityService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use App\Services\Trajectory\SalesTaxService;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->cash = Account::create(['space_id' => $this->space->id, 'name' => 'Fictional tax bank', 'kind' => 'cash', 'source_key' => 'test:purpose-bank', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2027-01-01']);
    $this->postTax = function (string $key = 'payment', int $amount = -10000, ?array $components = [9000, 200, 700, 100], string $movement = 'payment', ?Account $account = null): array {
        $account ??= $this->cash;
        app(LedgerService::class)->ingest($account, [['id' => $key, 'date' => '2028-07-20', 'merchant' => 'Fictional revenue agency', 'amount_cents' => $amount]]);
        $tx = Transaction::where('source_key', $account->source_key.':'.$key)->firstOrFail();
        $input = ['transaction_id' => $tx->id, 'paid_on' => '2028-07-20', 'amount_cents' => $amount, 'agency' => 'Fictional revenue agency', 'tax_type' => 'sales_tax', 'movement' => $movement, 'source' => 'Fictional agency receipt', 'reviewed' => true,
            'expected_bank_source_fingerprint' => app(SalesTaxService::class)->bankFingerprint($this->space->id, $account->id, $tx->id, '2028-07-20', $amount, $tx->merchant)];
        if ($components !== null) {
            $input = [...$input, ...array_combine(['tax_principal_cents', 'interest_cents', 'penalty_cents', 'other_fees_cents'], $components)];
        }
        $record = app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', 'Fictional filing '.$key, $input);

        return [$tx, $record, $input];
    };
    $this->classifyTax = fn (Transaction $tx, array $change = []) => $this->actingAs($this->owner)->patchJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id,
        ['version' => $tx->fresh()->version, 'category' => 'taxes', 'flow' => 'sales_tax_remittance', 'face_punched' => false, 'bullshit_spending' => false, ...$change]);
    $this->entries = fn () => collect(app(LedgerService::class)->entries($this->space));
    $this->dashboard = fn () => app(DashboardService::class)->build($this->space, 'month', viewer: $this->owner);
});

test('sales tax purpose is an explicit source backed review preserving bank cents and excluding principal consistently', function (): void {
    [$tx, $record] = ($this->postTax)();
    expect($tx->fresh()->reviewed)->toBeFalse(); // Saving filing evidence does not silently classify the bank.
    ($this->classifyTax)($tx)->assertOk();
    expect($tx->fresh()->amount_cents)->toBe(-10000)->and($this->cash->fresh()->balance_cents)->toBe(100000)
        ->and(Record::where('kind', 'rule')->count())->toBe(0)->and(Transaction::count())->toBe(1);
    $entries = ($this->entries)();
    $economic = app(EconomicActivityService::class);
    expect($economic->total($entries))->toBe(1000)->and($economic->total($entries, true))->toBe(800)
        ->and($economic->expenses($entries)->pluck('id')->unique()->all())->toBe([$tx->id]);
    $report = ($this->dashboard)();
    expect($report['summary']['spending_cents'])->toBe(1000)->and($report['daily_series'][0]['spending_cents'])->toBe(1000)->and($report['spending_analysis']['transaction_count'])->toBe(1)
        ->and($report['history']['months'][0]['spending_cents'])->toBe(1000)
        ->and(app(\App\Services\Trajectory\TaxReviewService::class)->profitLoss($entries, [])['expenses_cents'])->toBe(1000)
        ->and($report['decision_support']['month']['spent_cents'])->toBe(1000)
        ->and($report['planning']['historical_flexible_cents'])->toBe(0)
        ->and($report['planning']['tax_cash_plan_needed'])->toBeTrue();
    $views = $report['money_flow']['views'];
    expect($views['cash']['outflow_cents'])->toBe(10000)->and($views['cash']['outflows'][0]['key'])->toBe('sales_tax_cash')
        ->and($views['spending']['outflow_cents'])->toBe(1000)
        ->and(collect($views['spending']['excluded'])->firstWhere('key', 'sales_tax_principal')['amount_cents'])->toBe(9000);
    expect($report['sales_tax']['confirmed_payment_cents'])->toBe(10000)->and($record->fresh()->data['amount_cents'])->toBe(-10000);
});

test('principal only is a verified zero economic cost while unknown split remains unavailable and fully inspectable', function (): void {
    [$principal] = ($this->postTax)('principal', -12345, [12345, 0, 0, 0]);
    ($this->classifyTax)($principal)->assertOk();
    expect(($this->dashboard)()['summary']['spending_cents'])->toBe(0);
    [$unknown] = ($this->postTax)('unknown', -23456, null);
    ($this->classifyTax)($unknown)->assertOk();
    $d = ($this->dashboard)();
    expect($d['summary']['spending_cents'])->toBeNull()->and($d['economic_coverage']['unresolved_ids'])->toBe([$unknown->id])
        ->and($d['history']['months'][0]['spending_cents'])->toBeNull()->and(app(\App\Services\Trajectory\TaxReviewService::class)->profitLoss(($this->entries)(), [])['expenses_cents'])->toBeNull()
        ->and(app(\App\Services\Trajectory\TaxReviewService::class)->profitLoss(($this->entries)(), [])['net_cents'])->toBeNull()->and($d['decision_support']['month']['spent_cents'])->toBeNull()
        ->and($d['money_flow']['views']['spending']['outflow_cents'])->toBeNull()
        ->and($d['money_flow']['views']['spending']['tax_split_unknown_gross_cents'])->toBe(23456)
        ->and($d['money_flow']['views']['cash']['outflow_cents'])->toBe(35801);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$unknown->id], 'economic_costs' => true])->assertOk()
        ->assertJsonPath('0.amount_cents', null)->assertJsonPath('0.bank_amount_cents', -23456);
});

test('tax returned payments and refunds reverse only their known cost components and never create earned income', function (): void {
    foreach ([['payment', -10000, 'payment'], ['returned', 10000, 'returned_payment'], ['refund', 10000, 'refund']] as [$key, $amount, $movement]) {
        [$tx] = ($this->postTax)($key, $amount, [9000, 200, 700, 100], $movement);
        ($this->classifyTax)($tx)->assertOk();
    }
    $d = ($this->dashboard)();
    expect($d['summary']['income_cents'])->toBe(0)->and($d['summary']['spending_cents'])->toBe(-1000)
        ->and($d['income_sources']['earned_income_cents'])->toBe(0)
        ->and(collect($d['income_sources']['sources'])->firstWhere('key', 'sales-tax-returns-refunds')['amount_cents'] ?? null)->not->toBeNull();
    $v = $d['money_flow']['views'];
    expect($v['cash']['inflow_cents'])->toBe(20000)->and($v['cash']['outflow_cents'])->toBe(10000)
        ->and($v['spending']['inflow_cents'])->toBe(2000)->and($v['spending']['outflow_cents'])->toBe(1000);
    expect($d['sales_tax']['net_remittance_cents'])->toBe(-10000);
});

test('admission requires reviewed current owned cash evidence and cannot propagate an agency rule', function (): void {
    [$tx, $record, $input] = ($this->postTax)();
    foreach (['expense', 'transfer', 'debt_payment', 'income', 'refund'] as $wrongFlow) {
        ($this->classifyTax)($tx, ['flow' => $wrongFlow])->assertUnprocessable();
    }
    ($this->classifyTax)($tx, ['learn' => true])->assertUnprocessable();
    ($this->classifyTax)($tx, ['category' => 'materials'])->assertUnprocessable();
    app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', $record->name, [...$input, 'reviewed' => false], $record, $record->version);
    ($this->classifyTax)($tx)->assertUnprocessable();
    $record = $record->fresh();
    app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', $record->name, $input, $record, $record->version);
    $tx->update(['merchant' => 'Amended agency']);
    ($this->classifyTax)($tx)->assertUnprocessable();
    expect(Record::where('kind', 'rule')->count())->toBe(0);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->patchJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id, ['version' => $tx->version, 'category' => 'taxes', 'flow' => 'sales_tax_remittance', 'face_punched' => false, 'bullshit_spending' => false])->assertForbidden();
});

test('archived changed and stale source bindings cannot keep old sales tax economic treatment reviewed', function (): void {
    [$tx, $record, $input] = ($this->postTax)();
    ($this->classifyTax)($tx)->assertOk();
    app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', $record->name, [...$input, 'source' => 'Fictional note amended only'], $record, $record->version);
    expect($tx->fresh()->reviewed)->toBeTrue();
    $record = $record->fresh();
    app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', $record->name, [...$input, 'tax_type' => 'income_tax'], $record, $record->version);
    expect($tx->fresh()->reviewed)->toBeFalse()->and(app(EconomicActivityService::class)->total(($this->entries)()))->toBeNull();
    $record = $record->fresh();
    app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', $record->name, $input, $record, $record->version);
    ($this->classifyTax)($tx)->assertOk();
    $record = $record->fresh();
    $this->deleteJson('/trajectory/spaces/'.$this->space->id.'/records/'.$record->id, ['version' => $record->version])->assertOk();
    expect($tx->fresh()->reviewed)->toBeFalse()->and(($this->entries)()[0]['tax_purpose']['valid'])->toBeFalse();
});

test('tax source amendments are caught on read and reingest and remain economic unknown rather than zero', function (): void {
    [$tx] = ($this->postTax)();
    ($this->classifyTax)($tx)->assertOk();
    app(LedgerService::class)->ingest($this->cash, [['id' => 'payment', 'date' => '2028-07-20', 'merchant' => 'Fictional revenue agency amended', 'amount_cents' => -10001]]);
    expect($tx->fresh()->reviewed)->toBeFalse()->and(($this->entries)()[0]['reviewed'])->toBeFalse()->and(($this->dashboard)()['summary']['spending_cents'])->toBeNull()
        ->and(($this->dashboard)()['money_flow']['views']['cash']['outflow_cents'])->toBe(10001);
});

test('sales tax allocation guards preserve source cash and never disclose foreign evidence', function (): void {
    [$tx] = ($this->postTax)();
    Allocation::where('transaction_id', $tx->id)->update(['amount_cents' => -9000]);
    ($this->classifyTax)($tx)->assertUnprocessable();
    Allocation::where('transaction_id', $tx->id)->update(['amount_cents' => -10000]);
    ($this->classifyTax)($tx)->assertOk();
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id.'/split', ['version' => $tx->fresh()->version, 'splits' => [['space_id' => $this->space->id, 'amount_cents' => -10000]]])->assertUnprocessable();
    expect(Allocation::where('transaction_id', $tx->id)->first()->amount_cents)->toBe(-10000)->and($tx->fresh()->amount_cents)->toBe(-10000);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    Allocation::create(['transaction_id' => $tx->id, 'space_id' => $foreign->id, 'amount_cents' => -50]);
    $this->actingAs($other)->postJson('/trajectory/spaces/'.$foreign->id.'/evidence', ['ids' => [$tx->id], 'economic_costs' => true])->assertOk()
        ->assertJsonPath('0.amount_cents', null)->assertJsonPath('0.merchant', 'Shared allocation')->assertJsonMissingPath('0.tax_purpose.record_id');
});

test('economic branch evidence reconciles each component to the original bank without adding a transaction', function (): void {
    [$tx] = ($this->postTax)();
    ($this->classifyTax)($tx)->assertOk();
    foreach (['interest' => -200, 'tax_penalties' => -700, 'tax_fees' => -100] as $category => $amount) {
        $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'economic_costs' => true, 'economic_category' => $category])
            ->assertOk()->assertJsonPath('0.id', $tx->id)->assertJsonPath('0.amount_cents', $amount)->assertJsonPath('0.bank_amount_cents', -10000)
            ->assertJsonPath('0.amount_basis', 'tax_economic_cost')->assertJsonPath('0.economic_components.0.category', $category);
    }
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'economic_costs' => true, 'economic_category' => 'sales_tax_principal'])->assertOk()->assertJsonPath('0.amount_cents', -9000)->assertJsonPath('0.bank_amount_cents', -10000)->assertJsonPath('0.amount_basis', 'sales_tax_principal');
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true])->assertOk()->assertJsonPath('0.amount_cents', -10000);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true, 'economic_costs' => true])->assertUnprocessable();
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'economic_costs' => true, 'economic_categories' => ['interest', 'tax_fees']])->assertOk()->assertJsonPath('0.amount_cents', -300)->assertJsonCount(2, '0.economic_components');
    expect(Transaction::count())->toBe(1);
});

test('card sources and legacy agency rules cannot establish sales tax bank purpose', function (): void {
    $card = Account::create(['space_id' => $this->space->id, 'name' => 'Fictional card', 'kind' => 'credit', 'source_key' => 'test:purpose-card', 'balance_cents' => 10000]);
    [$tx] = ($this->postTax)('card-tax', account: $card);
    ($this->classifyTax)($tx)->assertUnprocessable();
    Record::create(['space_id' => $this->space->id, 'kind' => 'rule', 'name' => 'Invalid legacy tax rule', 'data' => ['merchant' => 'Fictional revenue agency', 'classification' => ['category' => 'taxes', 'flow' => 'sales_tax_remittance', 'face_punched' => false, 'bullshit_spending' => false]], 'active' => true]);
    $suggestion = app(\App\Services\Trajectory\ClassificationService::class)->suggest($this->space, 'Fictional revenue agency', -10000);
    expect($suggestion['flow'])->toBe('expense')->and($suggestion['reviewed'])->toBeFalse();
});
