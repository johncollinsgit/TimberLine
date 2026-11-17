<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\CardActivityService;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25 12:00:00', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->card = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:activity-card', 'name' => 'Fictional credit card', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 131902, 'observed_at' => '2028-07-25']);
    $this->post = function (string $id, int $amount, string $flow, string $category, bool $reviewed = true, ?Account $account = null, string $date = '2028-07-20'): Transaction {
        $account ??= $this->card;
        app(LedgerService::class)->ingest($account, [['id' => $id, 'date' => $date, 'merchant' => 'Fictional '.$id, 'amount_cents' => $amount]]);
        $row = Transaction::where('account_id', $account->id)->where('source_key', $account->source_key.':'.$id)->sole();
        $row->update(['flow' => $flow, 'category' => $category, 'reviewed' => $reviewed]);

        return $row;
    };
    $this->observe = fn (int $amount, string $date, ?Account $account = null) => Record::create(['space_id' => $this->space->id, 'kind' => 'debt_balance_observation', 'name' => 'Fictional reconstructed opening', 'active' => true, 'data' => ['account_id' => ($account ?? $this->card)->id, 'balance_cents' => $amount, 'observed_on' => $date, 'source' => 'Fictional reviewed statement reconstruction; not a direct opening-date screenshot.', 'reviewed' => true]]);
    $this->report = fn (string $from = '2028-01-01', string $through = '2028-07-25') => app(CardActivityService::class)->build($this->space, Account::where('space_id', $this->space->id)->get(), Record::where('space_id', $this->space->id)->get(), $from, $through, $this->owner);
    $this->evidenceUrl = '/trajectory/spaces/'.$this->space->id.'/evidence';
});

test('owned card sources conserve purchases metals refunds fees repayments reversals and unknown purposes without inventing income', function (): void {
    ($this->observe)(100000, '2028-01-01');
    ($this->post)('purchase', -10001, 'expense', 'shopping');
    ($this->post)('gold', -25000, 'asset_transfer', 'gold_and_silver');
    ($this->post)('gold-return', 5000, 'asset_transfer', 'gold_and_silver');
    ($this->post)('interest', -345, 'expense', 'interest');
    ($this->post)('fee', -200, 'expense', 'fees');
    ($this->post)('payment', 15000, 'card_payment', 'uncategorized');
    ($this->post)('returned-payment', -2000, 'card_payment', 'uncategorized');
    ($this->post)('unknown', -777, 'expense', 'shopping', false);
    ($this->post)('other-asset', -1234, 'asset_transfer', 'uncategorized');
    ($this->post)('equipment', -12345, 'expense', 'shopping');
    $card = ($this->report)()['cards'][0];
    $groups = collect($card['groups'])->keyBy('key');
    expect($card['posted_count'])->toBe(10)->and($groups['metals']['debits_cents'])->toBe(25000)->and($groups['metals']['credits_cents'])->toBe(5000)
        ->and($groups['metals']['net_outlay_cents'])->toBe(20000)->and($groups['payments']['net_outlay_cents'])->toBe(-13000)
        ->and($groups['needs_review']['debits_cents'])->toBe(777)->and($groups['other_assets']['debits_cents'])->toBe(1234)
        ->and(array_sum(array_column($card['groups'], 'net_outlay_cents')))->toBe(31902)
        ->and($card['recorded_charges_cents'] - $card['recorded_credits_cents'])->toBe(31902)
        ->and($card['jan1_balance_change_cents'])->toBe(31902)->and($card['bridge']['calculated_closing_cents'])->toBe(131902)
        ->and($card['bridge']['reconciles'])->toBeTrue()->and($card['bridge']['complete_coverage'])->toBeFalse();
});

test('zero household allocation preserves physical card charges without exposing another space or adding economic cost twice', function (): void {
    $row = ($this->post)('client-equipment', -12345, 'expense', 'shopping');
    [$foreign] = app(PilotService::class)->prepare(User::factory()->create(), null, true);
    $foreign->update(['name' => 'Private foreign company name']);
    Allocation::where('transaction_id', $row->id)->update(['amount_cents' => 0]);
    Allocation::create(['transaction_id' => $row->id, 'space_id' => $foreign->id, 'amount_cents' => -12345]);
    $card = ($this->report)()['cards'][0];
    expect($card['recorded_charges_cents'])->toBe(12345)->and($card['purchase_allocation']['space_share_cents'])->toBe(0)
        ->and($card['purchase_allocation']['outside_share_cents'])->toBe(12345)->and(json_encode($card))->not->toContain($foreign->name);
    $this->actingAs($this->owner)->postJson($this->evidenceUrl, ['ids' => [$row->id], 'account_id' => $this->card->id, 'credit_movement' => true])
        ->assertOk()->assertJsonPath('0.amount_cents', -12345)->assertJsonPath('0.allocated_amount_cents', 0)->assertJsonPath('0.amount_basis', 'owned_credit_activity');
    $this->postJson($this->evidenceUrl, ['ids' => [$row->id]])->assertOk()->assertJsonPath('0.amount_cents', 0);
    $this->postJson($this->evidenceUrl, ['ids' => [$row->id], 'cash_movement' => true])->assertOk()->assertExactJson([]);
});

test('missing partial or inconsistent allocations remain unavailable rather than becoming a zero share', function (string $shape): void {
    $row = ($this->post)('allocation', -10000, 'expense', 'shopping');
    if ($shape === 'missing') {
        Allocation::where('transaction_id', $row->id)->delete();
    } else {
        Allocation::where('transaction_id', $row->id)->update(['amount_cents' => -100]);
    }
    $card = ($this->report)()['cards'][0];
    expect($card['recorded_charges_cents'])->toBe(10000)->and($card['purchase_allocation']['space_share_cents'])->toBeNull()
        ->and($card['purchase_allocation']['outside_share_cents'])->toBeNull();
    $this->actingAs($this->owner)->postJson($this->evidenceUrl, ['account_id' => $this->card->id, 'credit_movement' => true])
        ->assertOk()->assertJsonPath('0.amount_cents', -10000)->assertJsonPath('0.allocated_amount_cents', null);
})->with(['missing', 'partial']);

test('missing opening stale closing conflicting observations and historical windows cannot fabricate an annual change', function (): void {
    ($this->post)('purchase', -10001, 'expense', 'shopping');
    expect(($this->report)()['cards'][0]['jan1_balance_change_cents'])->toBeNull();
    ($this->observe)(100000, '2028-01-01');
    $this->card->update(['observed_at' => '2028-07-24']);
    $card = ($this->report)()['cards'][0];
    expect($card['latest_balance']['amount_cents'])->toBe(131902)->and($card['latest_balance']['observed_on'])->toBe('2028-07-24')
        ->and($card['closing']['amount_cents'])->toBeNull()->and($card['jan1_balance_change_cents'])->toBeNull();
    $this->card->update(['observed_at' => '2028-07-25']);
    ($this->observe)(90000, '2027-12-31');
    expect(($this->report)()['cards'][0]['opening']['conflict'])->toBeTrue()->and(($this->report)()['cards'][0]['jan1_balance_change_cents'])->toBeNull();
    ($this->observe)(200000, '2027-01-01');
    $prior = ($this->report)('2027-01-01', '2027-12-31')['cards'][0];
    expect($prior['closing']['amount_cents'])->toBe(90000)->and($prior['latest_balance']['observed_on'])->toBe('2028-07-25');
});

test('unreconciled sources show a difference and empty periods do not claim zero activity or pending amounts', function (): void {
    expect(($this->report)()['cards'][0]['recorded_charges_cents'])->toBeNull();
    ($this->observe)(100000, '2028-01-01');
    ($this->post)('purchase', -10001, 'expense', 'shopping');
    ($this->post)('pending', -5555, 'expense', 'shopping')->update(['pending' => true]);
    ($this->post)('future', -6666, 'expense', 'shopping', date: '2028-07-26');
    $card = ($this->report)()['cards'][0];
    expect($card['posted_count'])->toBe(1)->and($card['recorded_charges_cents'])->toBe(10001)
        ->and($card['bridge']['difference_cents'])->toBe(21901)->and($card['bridge']['reconciles'])->toBeFalse()
        ->and(json_encode($card['notes']))->toContain('Pending activity');
    ($this->post)('duplicate', -9000, 'duplicate', 'shopping');
    expect(($this->report)()['cards'][0]['bridge']['calculated_closing_cents'])->toBeNull();
});

test('account date and currency scopes remain separate and wrong source kinds cannot use credit evidence', function (): void {
    $euro = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:euro-card', 'name' => 'Fictional EUR card', 'kind' => 'credit', 'currency' => 'EUR', 'balance_cents' => 5000]);
    ($this->post)('usd', -10001, 'expense', 'shopping');
    ($this->post)('euro', -2345, 'expense', 'shopping', account: $euro);
    $data = app(DashboardService::class)->build($this->space, 'custom', from: '2028-07-20', through: '2028-07-20', viewer: $this->owner, accountId: $euro->id);
    expect($data['card_activity']['cards'])->toHaveCount(1)->and($data['card_activity']['cards'][0]['currency'])->toBe('EUR')
        ->and($data['card_activity']['cards'][0]['recorded_charges_cents'])->toBe(2345);
    $this->actingAs($this->owner)->postJson($this->evidenceUrl, ['account_id' => $euro->id, 'date' => '2028-07-21', 'credit_movement' => true])->assertOk()->assertExactJson([]);
    $cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:cash', 'name' => 'Fictional cash', 'kind' => 'cash']);
    $this->postJson($this->evidenceUrl, ['account_id' => $cash->id, 'credit_movement' => true])->assertUnprocessable();
    $this->postJson($this->evidenceUrl, ['account_id' => $euro->id, 'credit_movement' => true, 'cash_movement' => true])->assertUnprocessable();
    $this->postJson($this->evidenceUrl, ['account_id' => $euro->id, 'credit_movement' => true, 'economic_costs' => true])->assertUnprocessable();
});

test('foreign shared card sources and unauthorized viewers never disclose bank amounts merchants or other businesses', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    $account = Account::create(['space_id' => $foreign->id, 'source_key' => 'fictional:foreign-card', 'name' => 'Secret card', 'kind' => 'credit']);
    $row = ($this->post)('secret-merchant', -99999, 'expense', 'shopping', account: $account);
    Allocation::create(['transaction_id' => $row->id, 'space_id' => $this->space->id, 'amount_cents' => -10]);
    expect(json_encode(($this->report)()))->not->toContain('Secret', 'secret-merchant', '99999');
    $this->actingAs($this->owner)->postJson($this->evidenceUrl, ['account_id' => $this->card->id, 'ids' => [$row->id], 'credit_movement' => true])->assertOk()->assertExactJson([]);
    $this->postJson($this->evidenceUrl, ['account_id' => $account->id, 'credit_movement' => true])->assertNotFound();
    $this->actingAs($other)->postJson($this->evidenceUrl, ['account_id' => $this->card->id, 'credit_movement' => true])->assertForbidden();
    expect(app(CardActivityService::class)->build($this->space, collect([$this->card]), collect(), '2028-01-01', '2028-07-25', $other))->toBeNull();
});

test('current tenant financial membership is required for the owned business credit selector', function (): void {
    $tenant = Tenant::create(['slug' => 'fictional-credit-business', 'name' => 'Fictional credit business']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [$business] = app(PilotService::class)->prepare($this->owner, $tenant, true, 'business');
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $account = Account::create(['space_id' => $business->id, 'source_key' => 'fictional:business-card', 'name' => 'Fictional business card', 'kind' => 'credit']);
    $this->owner->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$business->id.'/evidence', ['account_id' => $account->id, 'credit_movement' => true])->assertForbidden();
});

test('explicit zero card rows retain zero while unknown currency and a following January opening do not fabricate boundaries', function (): void {
    ($this->post)('zero', 0, 'expense', 'shopping');
    $card = ($this->report)()['cards'][0];
    expect($card['recorded_charges_cents'])->toBe(0)->and($card['recorded_credits_cents'])->toBe(0)->and($card['groups'][0]['key'])->toBe('zero');
    ($this->observe)(50000, '2027-01-01');
    ($this->observe)(100000, '2028-01-01');
    expect(($this->report)('2027-01-01', '2027-12-31')['cards'][0]['closing']['amount_cents'])->toBeNull();
    $this->card->update(['currency' => '']);
    $invalid = ($this->report)()['cards'][0];
    expect($invalid['currency'])->toBeNull()->and($invalid['recorded_charges_cents'])->toBeNull()
        ->and($invalid['opening']['amount_cents'])->toBeNull()->and($invalid['latest_balance']['amount_cents'])->toBeNull();
});

test('future-only reports have valid future dates but no actual period closing or invented activity', function (): void {
    ($this->post)('present', -1000, 'expense', 'shopping');
    ($this->observe)(90000, '2029-01-01');
    $report = ($this->report)('2029-01-01', '2029-03-31');
    $card = $report['cards'][0];
    expect($report['from'])->toBe('2029-01-01')->and($report['through'])->toBe('2029-03-31')->and($card['period_available'])->toBeFalse()
        ->and($card['posted_count'])->toBe(0)->and($card['recorded_charges_cents'])->toBeNull()->and($card['opening']['amount_cents'])->toBeNull()
        ->and($card['closing']['amount_cents'])->toBeNull()->and($card['jan1_balance_change_cents'])->toBeNull()
        ->and($card['latest_balance']['amount_cents'])->toBe(131902)->and($card['latest_balance']['observed_on'])->toBe('2028-07-25');
});

test('all dates includes older owned card sources without allocations while custom dates remain bounded', function (): void {
    $older = ($this->post)('older-unallocated', -7500, 'expense', 'shopping', date: '2027-03-20');
    Allocation::where('transaction_id', $older->id)->delete();
    ($this->post)('current', -2500, 'expense', 'shopping');
    $all = app(DashboardService::class)->build($this->space, 'all', viewer: $this->owner, accountId: $this->card->id);
    $card = $all['card_activity']['cards'][0];
    expect($card['from'])->toBe('2027-03-20')->and($card['posted_count'])->toBe(2)
        ->and($card['recorded_charges_cents'])->toBe(10000)->and($card['purchase_allocation']['space_share_cents'])->toBeNull()
        ->and($card['purchase_allocation']['missing_ids'])->toBe([$older->id]);
    $custom = app(DashboardService::class)->build($this->space, 'custom', from: '2028-01-01', through: '2028-07-25', viewer: $this->owner, accountId: $this->card->id);
    expect($custom['card_activity']['cards'][0]['posted_count'])->toBe(1)->and($custom['card_activity']['cards'][0]['recorded_charges_cents'])->toBe(2500);
});

test('reviewed interest and fee refunds offset their own category instead of becoming purchase credits', function (): void {
    ($this->post)('interest', -1000, 'expense', 'interest');
    ($this->post)('interest-refund', 300, 'refund', 'interest');
    ($this->post)('fee', -500, 'expense', 'fees');
    ($this->post)('fee-refund', 100, 'refund', 'fees');
    ($this->post)('uncategorized-reviewed', -100, 'expense', 'uncategorized');
    $card = ($this->report)()['cards'][0];
    $groups = collect($card['groups'])->keyBy('key');
    expect($groups['interest']['net_outlay_cents'])->toBe(700)->and($groups['fees']['net_outlay_cents'])->toBe(400)
        ->and($groups->has('purchases'))->toBeFalse()->and($card['review_count'])->toBe(1)
        ->and($card['recorded_credits_cents'])->toBe(400);
});
