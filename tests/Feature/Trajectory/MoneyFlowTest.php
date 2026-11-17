<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MoneyFlowService;
use App\Services\Trajectory\PilotService;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:flow-cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'balance_cents' => 500000]);
    $this->card = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:flow-card', 'name' => 'Fictional card', 'kind' => 'credit', 'balance_cents' => 100000]);
    $this->post = function (string $id, int $amount, string $flow, string $category, ?Account $account = null, bool $reviewed = true, string $merchant = 'Fictional merchant'): Transaction {
        $account ??= $this->cash;
        app(LedgerService::class)->ingest($account, [['id' => $id, 'date' => '2028-07-20', 'merchant' => $merchant, 'amount_cents' => $amount]]);
        $tx = Transaction::where('account_id', $account->id)->where('source_key', $account->source_key.':'.$id)->firstOrFail();
        $tx->update(['flow' => $flow, 'category' => $category, 'reviewed' => $reviewed]);

        return $tx;
    };
    $this->report = fn () => app(MoneyFlowService::class)->build($this->space, collect(app(LedgerService::class)->entries($this->space)), collect([$this->cash, $this->card]));
});

test('spending counts card purchases once and cash movement counts the actual repayment separately', function (): void {
    ($this->post)('income', 200001, 'income', 'income');
    ($this->post)('purchase', -5023, 'expense', 'food', $this->card);
    ($this->post)('repay-bank', -5023, 'card_payment', 'debt');
    ($this->post)('repay-card', 5023, 'card_payment', 'debt', $this->card);
    ($this->post)('internal-out', -10000, 'transfer', 'uncategorized');
    ($this->post)('internal-in', 10000, 'transfer', 'uncategorized');
    $report = ($this->report)()['views'];
    foreach (['spending', 'cash'] as $mode) {
        expect($report[$mode]['inflow_cents'])->toBe(200001)->and($report[$mode]['outflow_cents'])->toBe(5023)->and($report[$mode]['difference_cents'])->toBe(194978);
        expect(array_sum(array_column($report[$mode]['inflows'], 'amount_cents')))->toBe($report[$mode]['inflow_cents'])
            ->and(array_sum(array_column($report[$mode]['outflows'], 'amount_cents')))->toBe($report[$mode]['outflow_cents']);
    }
    expect($report['spending']['outflows'][0]['label'])->toBe('Food')->and($report['cash']['outflows'][0]['label'])->toBe('Debt repayments');
});

test('unknown and unreviewed posted money stays explicit while borrowing and asset sales are separate from income', function (): void {
    ($this->post)('wages', 12000, 'income', 'income', merchant: 'Fictional employer');
    ($this->post)('borrow', 7000, 'loan_draw', 'income');
    ($this->post)('asset', 3000, 'asset_sale', 'income');
    ($this->post)('unknown', 1100, 'unclassified_deposit', 'uncategorized');
    ($this->post)('unreviewed-income', 1200, 'income', 'income', reviewed: false);
    ($this->post)('unreviewed-expense', -2345, 'expense', 'food', reviewed: false);
    $view = ($this->report)()['views']['spending'];
    expect($view['inflow_cents'])->toBe(24300)->and($view['review_inflow_cents'])->toBe(2300)->and($view['review_outflow_cents'])->toBe(2345);
    expect(collect($view['inflows'])->pluck('kind')->unique()->values()->all())->toBe(['income', 'borrowing', 'asset', 'review']);
    expect($view['outflows'][0]['key'])->toBe('needs_review')->and($view['outflows'][0]['merchants'][0]['amount_cents'])->toBe(2345);
});

test('refunds conserve gross cents and only known refund categories receive a net spending row', function (): void {
    ($this->post)('purchase', -10001, 'expense', 'food');
    ($this->post)('known-refund', 12001, 'refund', 'food');
    ($this->post)('unknown-credit', 999, 'income', 'uncategorized', reviewed: false);
    $view = ($this->report)()['views']['spending'];
    expect($view['inflow_cents'])->toBe(13000)->and($view['outflow_cents'])->toBe(10001)->and($view['difference_cents'])->toBe(2999);
    expect($view['refund_categories'])->toHaveCount(1)->and($view['refund_categories'][0]['gross_cents'])->toBe(10001)
        ->and($view['refund_categories'][0]['refund_cents'])->toBe(12001)->and($view['refund_categories'][0]['net_cents'])->toBe(-2000);
});

test('spending includes only the authorized shared allocation while cash uses owned full bank amounts', function (): void {
    $tx = ($this->post)('split', -10000, 'expense', 'food');
    Allocation::where('transaction_id', $tx->id)->update(['amount_cents' => -2500]);
    $other = User::factory()->create();
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    $foreignAccount = Account::create(['space_id' => $foreign->id, 'source_key' => 'test:flow-foreign', 'name' => 'Private foreign bank', 'kind' => 'cash', 'balance_cents' => 0]);
    $shared = ($this->post)('shared', -8000, 'expense', 'clothing', $foreignAccount, merchant: 'Private foreign merchant');
    Allocation::create(['transaction_id' => $shared->id, 'space_id' => $this->space->id, 'amount_cents' => -1500]);
    $view = ($this->report)()['views'];
    expect($view['spending']['outflow_cents'])->toBe(4000)->and($view['cash']['outflow_cents'])->toBe(10000)
        ->and($view['spending']['shared_allocation_count'])->toBe(1);
    expect(json_encode($view))->not->toContain('Private foreign')->and(collect($view['spending']['outflows'])->firstWhere('key', 'clothing')['merchants'][0]['label'])->toBe('Shared allocation');
    expect(collect($view['cash']['excluded'])->firstWhere('key', 'noncash')['ids'])->toContain($shared->id);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$shared->id], 'cash_movement' => true])->assertOk()->assertExactJson([]);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$shared->id]])->assertOk()->assertJsonPath('0.amount_cents', -1500)->assertJsonPath('0.merchant', 'Shared allocation');
});

test('empty coverage stays unknown zero records remain inspectable and duplicated inputs never count twice', function (): void {
    expect(($this->report)()['views']['spending']['difference_cents'])->toBeNull();
    $tx = ($this->post)('zero', 0, 'expense', 'food');
    $entries = collect(app(LedgerService::class)->entries($this->space));
    $report = app(MoneyFlowService::class)->build($this->space, $entries->concat($entries), collect([$this->cash]));
    expect($report['views']['spending']['inflow_cents'])->toBe(0)->and($report['views']['spending']['zero_count'])->toBe(1)
        ->and($report['views']['spending']['excluded'][0]['ids'])->toBe([$tx->id])->and($report['coverage']['complete'])->toBeFalse();
});

test('cash source identity and status changes fail closed instead of fabricating a bank movement', function (): void {
    $tx = ($this->post)('stale', -1234, 'expense', 'food');
    $entries = collect(app(LedgerService::class)->entries($this->space));
    $tx->update(['version' => $tx->version + 1]);
    $report = app(MoneyFlowService::class)->build($this->space, $entries, collect([$this->cash]));
    expect($report['views']['cash']['stale_source_count'])->toBe(1)->and($report['views']['cash']['outflow_cents'])->toBeNull();
});

test('dashboard date and owned account filters apply to the same actual evidence without adding forecasts', function (): void {
    $tx = ($this->post)('selected', -1234, 'expense', 'food');
    ($this->post)('card', -5678, 'expense', 'transport', $this->card);
    $data = app(DashboardService::class)->build($this->space, 'custom', from: '2028-07-20', through: '2028-07-20', viewer: $this->owner, accountId: $this->cash->id);
    expect($data['money_flow']['views']['spending']['outflow_cents'])->toBe(1234)
        ->and($data['money_flow']['views']['spending']['outflows'][0]['ids'])->toBe([$tx->id]);
    $empty = app(DashboardService::class)->build($this->space, 'custom', from: '2028-07-21', through: '2028-07-21', viewer: $this->owner);
    expect($empty['money_flow']['views']['spending']['inflow_cents'])->toBeNull();
});

test('a zero owner allocation changes economic attribution without erasing the full owned bank cash outflow', function (): void {
    $tx = ($this->post)('all-business-cost', -77000, 'expense', 'clothing');
    Allocation::where('transaction_id', $tx->id)->update(['amount_cents' => 0]);
    $report = ($this->report)()['views'];
    expect($report['spending']['outflow_cents'])->toBe(0)->and($report['spending']['zero_count'])->toBe(1)
        ->and($report['cash']['outflow_cents'])->toBe(77000)->and($report['cash']['outflows'][0]['ids'])->toBe([$tx->id]);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true])
        ->assertOk()->assertJsonPath('0.amount_cents', -77000)->assertJsonPath('0.allocated_amount_cents', 0)->assertJsonPath('0.amount_basis', 'owned_bank_cash');
    Allocation::where('transaction_id', $tx->id)->update(['amount_cents' => -25000]);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true])
        ->assertOk()->assertJsonPath('0.amount_cents', -77000)->assertJsonPath('0.allocated_amount_cents', -25000);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id]])->assertOk()->assertJsonPath('0.amount_cents', -25000)->assertJsonMissingPath('0.allocated_amount_cents');
});

test('owned cash survives a missing owner allocation row without inventing an economic share', function (): void {
    $tx = ($this->post)('owner-allocation-absent', -12345, 'expense', 'housing');
    Allocation::where('transaction_id', $tx->id)->delete();
    $view = ($this->report)()['views'];
    expect($view['spending']['outflow_cents'])->toBeNull()->and($view['cash']['outflow_cents'])->toBe(12345)
        ->and($view['cash']['outflows'][0]['ids'])->toBe([$tx->id]);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true])
        ->assertOk()->assertJsonPath('0.amount_cents', -12345)->assertJsonPath('0.allocated_amount_cents', null);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id]])->assertOk()->assertExactJson([]);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true, 'date' => '2028-07-21'])->assertOk()->assertExactJson([]);
    $otherCash = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:other-empty-cash', 'name' => 'Fictional other cash', 'kind' => 'cash', 'balance_cents' => 0]);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id], 'cash_movement' => true, 'account_id' => $otherCash->id])->assertOk()->assertExactJson([]);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['date' => '2028-07-20', 'cash_movement' => true])->assertOk()->assertJsonPath('0.amount_cents', -12345)->assertJsonPath('0.allocated_amount_cents', null);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['account_id' => $this->cash->id, 'cash_movement' => true])->assertOk()->assertJsonPath('0.amount_cents', -12345)->assertJsonPath('0.allocated_amount_cents', null);
});

test('received medical shares stay separate from income and planned family support never enters actual flows', function (): void {
    ($this->post)('medical-share', 40000, 'medical_share', 'health');
    app(\App\Services\Trajectory\RecordService::class)->save($this->owner, $this->space, 'budget', 'Fictional planned family support', [
        'category' => 'income', 'monthly_cents' => 100000, 'purpose' => 'income', 'effective_on' => '2028-07-01', 'source' => 'Fictional plan only', 'reviewed' => true,
    ]);
    $data = app(DashboardService::class)->build($this->space, 'month', viewer: $this->owner);
    $view = $data['money_flow']['views']['spending'];
    expect($view['inflow_cents'])->toBe(40000)->and($view['inflows'])->toHaveCount(1)->and($view['inflows'][0]['kind'])->toBe('medical')
        ->and($view['inflows'][0]['label'])->toContain('bill reimbursements');
});

test('money flow uses existing financial authorization and evidence never discloses another space source', function (): void {
    $this->withoutVite();
    $tx = ($this->post)('private', -1234, 'expense', 'food');
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
    $this->actingAs($other)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$tx->id]])->assertForbidden();
    $this->actingAs($this->owner)->get('/trajectory')->assertOk()->assertSee('Money flow');
});

test('same period comparisons align leap dates and monthly history preserves missing future and scoped amounts', function (): void {
    $current = ($this->post)('leap', -10001, 'expense', 'housing');
    $current->update(['posted_on' => '2028-02-29']);
    $prior = ($this->post)('prior', -8003, 'expense', 'housing');
    $prior->update(['posted_on' => '2027-02-28']);
    $income = ($this->post)('prior-income', 20001, 'income', 'income');
    $income->update(['posted_on' => '2027-02-28']);
    $older = ($this->post)('older', -7007, 'expense', 'housing');
    $older->update(['posted_on' => '2026-02-28']);
    $card = ($this->post)('other-account', -9099, 'expense', 'housing', $this->card);
    $card->update(['posted_on' => '2027-02-28']);
    $history = app(DashboardService::class)->build($this->space, 'custom', from: '2028-02-29', through: '2028-02-29', viewer: $this->owner, accountId: $this->cash->id)['money_flow']['history'];
    expect($history['prior']['from'])->toBe('2027-02-28')->and($history['prior']['through'])->toBe('2027-02-28')
        ->and($history['prior']['views']['spending']['outflow_cents'])->toBe(8003)->and($history['comparable'])->toBeFalse();
    expect(array_column($history['years'], 'year'))->toBe([2026, 2027, 2028]);
    expect($history['years'][0]['months'][1]['views']['spending']['outflow_cents'])->toBe(7007)
        ->and($history['years'][1]['months'][1]['views']['cash']['outflow_cents'])->toBe(8003)
        ->and($history['years'][2]['months'][1]['views']['spending']['outflow_cents'])->toBe(10001)
        ->and($history['years'][2]['months'][0]['views']['spending']['outflow_cents'])->toBeNull()
        ->and($history['years'][2]['months'][7]['views']['spending']['outflow_cents'])->toBeNull()
        ->and($history['years'][2]['months'][7]['through'])->toBeNull();
    expect($history['prior']['views']['spending']['outflow_ids'])->toBe([$prior->id])
        ->and($history['prior']['views']['spending']['inflow_ids'])->toBe([$income->id])
        ->and($history['prior']['views']['spending']['ids'])->toContain($prior->id, $income->id);
});

test('reviewed financing and owner-support purposes do not require an expense category', function (): void {
    ($this->post)('draw', -10000, 'owner_distribution', 'uncategorized');
    ($this->post)('card-payment', -20000, 'card_payment', 'uncategorized');
    ($this->post)('loan-payment', -30000, 'debt_payment', 'uncategorized');
    ($this->post)('borrow', 40000, 'loan_draw', 'uncategorized');
    ($this->post)('unknown-expense', -1234, 'expense', 'uncategorized');
    $view = ($this->report)()['views']['cash'];
    expect(collect($view['outflows'])->firstWhere('key', 'owner_distributions')['amount_cents'])->toBe(10000)
        ->and(collect($view['outflows'])->firstWhere('key', 'debt_cash')['amount_cents'])->toBe(50000)
        ->and($view['review_outflow_cents'])->toBe(1234)->and($view['inflows'][0]['kind'])->toBe('borrowing');
});

test('excluded owned cash movements retain bank-basis evidence while economic exclusions stay allocated', function (): void {
    $tx = ($this->post)('allocated-transfer', -10000, 'transfer', 'uncategorized');
    Allocation::where('transaction_id', $tx->id)->update(['amount_cents' => 0]);
    $views = ($this->report)()['views'];
    $cash = collect($views['cash']['excluded'])->firstWhere('key', 'transfer');
    $spending = collect($views['spending']['excluded'])->firstWhere('key', 'transfer');
    expect($cash['amount_cents'])->toBe(10000)->and($cash['amount_basis'])->toBe('owned_bank_cash')
        ->and($spending['amount_cents'])->toBe(0)->and($spending['amount_basis'])->toBe('allocated');
});

test('authorized income labels preserve net-collection meaning without overriding financing medical or owner purposes', function (): void {
    $this->space->update(['settings' => [...($this->space->settings ?? []), 'income_categories' => ['fictional_net_collections' => 'Fictional net <bank> collections']]]);
    ($this->post)('net-collections', 10001, 'income', 'fictional_net_collections');
    ($this->post)('other-income', 2002, 'income', 'income');
    ($this->post)('loan', 3003, 'loan_draw', 'fictional_net_collections');
    ($this->post)('medical', 4004, 'medical_share', 'fictional_net_collections');
    ($this->post)('draw', 5005, 'owner_distribution', 'fictional_net_collections');
    $rows = ($this->report)()['views']['spending']['inflows'];
    expect($rows)->toHaveCount(5)->and($rows[0]['purpose_label'])->toBe('Fictional net <bank> collections')
        ->and($rows[0]['purpose_key'])->toBe('income:fictional_net_collections')->and($rows[1]['purpose_key'])->toBe('income')
        ->and(array_column($rows, 'kind'))->toBe(['income', 'income', 'borrowing', 'medical', 'support']);
});

test('payroll categories keep net owner studio event and unassigned cash separate without adding gross payroll', function (): void {
    $ids = [];
    foreach (['owner_payroll' => -12001, 'studio_payroll' => -23002, 'event_payroll' => -34003, 'payroll' => -4555] as $category => $amount) {
        $tx = ($this->post)('net-'.$category, $amount, 'expense', 'uncategorized', reviewed: false, merchant: 'Fictional payroll withdrawal');
        $this->actingAs($this->owner)->patchJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id, [
            'version' => $tx->version, 'category' => $category, 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false,
        ])->assertOk();
        $ids[$category] = $tx->id;
    }
    ($this->post)('draw-separate', -7777, 'owner_distribution', 'uncategorized');
    \App\Models\Trajectory\Record::create(['space_id' => $this->space->id, 'kind' => 'payroll', 'name' => 'Fictional gross payroll evidence', 'data' => ['reviewed' => true, 'period_end' => '2028-07-20', 'wages_cents' => 900000], 'active' => true]);
    $view = ($this->report)()['views'];
    foreach (['spending', 'cash'] as $mode) {
        $out = collect($view[$mode]['outflows'])->keyBy('key');
        foreach ($ids as $category => $id) {
            expect($out[$category]['ids'])->toBe([$id]);
        }
        expect($out['owner_payroll']['label'])->toBe('Owner payroll')->and($out['studio_payroll']['label'])->toBe('Studio Payroll')
            ->and($out['event_payroll']['label'])->toBe('Event Payroll')->and($out['payroll']['label'])->toBe('Payroll');
    }
    expect($view['spending']['outflow_cents'])->toBe(73561)->and($view['cash']['outflow_cents'])->toBe(81338)
        ->and(collect($view['cash']['outflows'])->firstWhere('key', 'owner_distributions')['ids'])->not->toContain($ids['owner_payroll'])
        ->and(Transaction::count())->toBe(5);
});

test('customer refund settlements conserve cash reduce income and never become overhead or purchase refunds', function (): void {
    ($this->post)('collection', 100000, 'income', 'income');
    $return = ($this->post)('customer-return', -6500, 'income', 'income', merchant: 'Fictional processor settlement');
    ($this->post)('materials', -20000, 'expense', 'materials');
    ($this->post)('purchase-return', 1000, 'refund', 'materials');
    $data = app(DashboardService::class)->build($this->space, 'month', viewer: $this->owner);
    foreach (['spending', 'cash'] as $mode) {
        $view = $data['money_flow']['views'][$mode];
        $refund = collect($view['outflows'])->firstWhere('key', 'customer_refunds');
        expect($view['inflow_cents'])->toBe(101000)->and($view['outflow_cents'])->toBe(26500)
            ->and($view['difference_cents'])->toBe(74500)->and($view['review_outflow_cents'])->toBe(0)
            ->and($refund['kind'])->toBe('customer_refund')->and($refund['label'])->toBe('Customer refunds / sales reversals')
            ->and($refund['amount_cents'])->toBe(6500)->and($refund['ids'])->toBe([$return->id])
            ->and($refund['preserve'])->toBeTrue()
            ->and(collect($view['refund_categories'])->pluck('key')->all())->toBe(['materials']);
    }
    expect($data['summary']['income_cents'])->toBe(93500)->and($data['summary']['spending_cents'])->toBe(19000)
        ->and($data['daily_series'][0]['income_cents'])->toBe(93500)
        ->and($data['history']['months'][0]['income_cents'])->toBe(93500)
        ->and(collect($data['categories'])->pluck('category')->all())->toBe(['materials']);
    $entries = collect(app(LedgerService::class)->entries($this->space));
    $profit = app(\App\Services\Trajectory\TaxReviewService::class)->profitLoss($entries, []);
    expect($profit['income_cents'])->toBe(93500)->and($profit['expenses_cents'])->toBe(19000)->and($profit['net_cents'])->toBe(74500)
        ->and(collect($data['planning']['sources'])->firstWhere('category', 'income')['ids'])->toContain($return->id);
    $sourceProfit = app(\App\Services\Trajectory\TaxReviewService::class)->profitLoss($entries, ['snapshot_id' => 99, 'income_cents' => 70000, 'net_income_cents' => 30000]);
    expect($sourceProfit['income_cents'])->toBe(70000)->and($sourceProfit['net_cents'])->toBe(30000);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$return->id], 'cash_movement' => true])
        ->assertOk()->assertJsonPath('0.amount_cents', -6500)->assertJsonPath('0.flow', 'income');
});

test('an unreviewed negative income row stays in outgoing review and does not net classified income', function (): void {
    $unknown = ($this->post)('unknown-return', -6500, 'income', 'income', reviewed: false);
    $view = ($this->report)()['views']['cash'];
    expect($view['outflow_cents'])->toBe(6500)->and($view['review_outflow_cents'])->toBe(6500)
        ->and($view['outflows'][0]['key'])->toBe('needs_review')->and($view['outflows'][0]['ids'])->toBe([$unknown->id]);
    $unknown->update(['reviewed' => true]);
    $data = app(DashboardService::class)->build($this->space, 'month', viewer: $this->owner);
    expect($data['summary']['income_cents'])->toBe(-6500)->and($data['summary']['spending_cents'])->toBe(0);
});
