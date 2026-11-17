<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\LenderInterestYtdService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25 12:00:00', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->card = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:annual-card', 'name' => 'Fictional card A', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 100000]);
    $this->second = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:annual-card-b', 'name' => 'Fictional card B', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 50000]);
    $this->evidence = ['account_id' => $this->card->id, 'reported_year' => 2028, 'reported_through' => '2028-06-20', 'interest_charged_cents' => 1234, 'currency' => 'USD', 'observed_on' => '2028-07-25', 'source_id' => 'fictional:annual-card:2028:June20', 'source' => 'Fictional lender statement, cumulative calendar-year table, page3.', 'source_sha256' => str_repeat('a', 64), 'reviewed' => true];
    $this->saveAnnual = fn ($data = [], $name = 'Fictional annual total') => app(RecordService::class)->save($this->owner, $this->space, 'lender_interest_ytd', $name, [...$this->evidence, ...$data]);
    $this->reportAnnual = fn ($from = '2028-01-01', $through = '2028-07-25') => app(LenderInterestYtdService::class)->build($this->space, Account::where('space_id', $this->space->id)->get(), Record::where('space_id', $this->space->id)->get(), $from, $through, $this->owner);
    $this->annualUrl = '/trajectory/spaces/'.$this->space->id.'/records';
});

test('annual lender evidence uses native audited idempotent saves and versioned corrections', function (): void {
    $payload = ['kind' => 'lender_interest_ytd', 'name' => 'Fictional annual total', 'data' => $this->evidence];
    $saved = $this->actingAs($this->owner)->postJson($this->annualUrl, $payload)->assertOk();
    $id = $saved->json('id');
    $this->postJson($this->annualUrl, $payload)->assertOk()->assertJsonPath('id', $id);
    $this->postJson($this->annualUrl, [...$payload, 'data' => [...$this->evidence, 'interest_charged_cents' => 999]])->assertConflict();
    $this->postJson($this->annualUrl, [...$payload, 'data' => [...$this->evidence, 'account_id' => $this->second->id]])->assertConflict();
    $r = Record::findOrFail($id);
    app(RecordService::class)->save($this->owner, $this->space, $r->kind, $r->name, [...$r->data, 'interest_charged_cents' => 1000], $r, 1);
    $this->patchJson($this->annualUrl.'/'.$id, ['kind' => $r->kind, 'version' => 1, 'name' => $r->name, 'data' => $r->data])->assertConflict();
    expect($r->fresh()->version)->toBe(2)->and(Record::where('kind', 'lender_interest_ytd')->count())->toBe(1);
});

test('annual record validation rejects incomplete foreign invalid year date and source facts', function (array $change): void {
    $this->actingAs($this->owner)->postJson($this->annualUrl, ['kind' => 'lender_interest_ytd', 'name' => 'Invalid source', 'data' => [...$this->evidence, ...$change]])->assertUnprocessable();
})->with([
    'lowercase currency' => [['currency' => 'usd']], 'wrong currency' => [['currency' => 'EUR']],
    'wrong year' => [['reported_year' => 2027]], 'future cutoff' => [['reported_through' => '2028-07-26']],
    'future verification' => [['observed_on' => '2028-07-26']], 'verification before cutoff' => [['observed_on' => '2028-06-19']],
    'negative interest' => [['interest_charged_cents' => -1]], 'missing amount' => [['interest_charged_cents' => null]],
    'hash not sha256' => [['source_sha256' => 'fake']], 'missing source' => [['source' => '  ']],
    'missing identity' => [['source_id' => ' ']], 'invalid date' => [['reported_through' => '2028-02-30']],
]);

test('latest per card and calendar year is selected once with exact zero and mixed cutoff coverage', function (): void {
    ($this->saveAnnual)();
    ($this->saveAnnual)(['reported_through' => '2028-06-30', 'source_id' => 'fictional:later', 'interest_charged_cents' => 0], 'Later source, explicit zero');
    $secondRecord = ($this->saveAnnual)(['account_id' => $this->second->id, 'source_id' => 'fictional:second', 'interest_charged_cents' => 5678], 'Second card');
    $year = ($this->reportAnnual)()['years'][0];
    expect($year['reported_subset_cents'])->toBe(5678)->and($year['reported_accounts'])->toBe(2)
        ->and($year['rows'][0]['interest_charged_cents'])->toBe(0)->and($year['rows'][0]['reported_through'])->toBe('2028-06-30')->and($year['different_cutoffs'])->toBeTrue();
    $this->second->update(['currency' => 'EUR']);
    $mismatch = ($this->reportAnnual)()['years'][0]['rows'][1];
    expect($mismatch['interest_charged_cents'])->toBeNull()->and($mismatch['currency'])->toBe('USD')->and($mismatch['issue'])->toContain('currency no longer matches');
    app(RecordService::class)->save($this->owner, $this->space, $secondRecord->kind, $secondRecord->name, [...$secondRecord->data, 'currency' => 'EUR', 'source_sha256' => str_repeat('b', 64)], $secondRecord, $secondRecord->version);
    $mixed = ($this->reportAnnual)()['years'][0];
    expect($mixed['reported_subset_cents'])->toBeNull()->and($mixed['different_currencies'])->toBeTrue();
});

test('conflicting same-cutoff reviewed records are unavailable while unreviewed inactive and future facts never become zero', function (): void {
    $r = ($this->saveAnnual)();
    Record::create(['space_id' => $this->space->id, 'kind' => 'lender_interest_ytd', 'name' => 'Fictional conflicting legacy record', 'active' => true, 'data' => [...$r->data, 'source_id' => 'fictional:conflict', 'interest_charged_cents' => 999]]);
    ($this->saveAnnual)(['account_id' => $this->second->id, 'source_id' => 'fictional:unreviewed', 'reviewed' => false]);
    $year = ($this->reportAnnual)()['years'][0];
    expect($year['rows'][0]['conflict'])->toBeTrue()->and($year['rows'][0]['interest_charged_cents'])->toBeNull()
        ->and($year['rows'][1]['interest_charged_cents'])->toBeNull()->and($year['reported_subset_cents'])->toBeNull();
    $r->update(['active' => false]);
    Record::where('name', 'Fictional conflicting legacy record')->update(['active' => false]);
    Record::create(['space_id' => $this->space->id, 'kind' => 'lender_interest_ytd', 'name' => 'Fictional future source', 'active' => true, 'data' => [...$this->evidence, 'reported_through' => '2028-08-01', 'observed_on' => '2028-08-01']]);
    expect(($this->reportAnnual)()['years'][0]['reported_accounts'])->toBe(0);
});

test('historical cutoff year selection account filters and future-only dates preserve evidence timing', function (): void {
    ($this->saveAnnual)();
    ($this->saveAnnual)(['reported_year' => 2027, 'reported_through' => '2027-12-15', 'observed_on' => '2027-12-16', 'source_id' => 'fictional:2027', 'interest_charged_cents' => 4321], 'Prior calendar year');
    $beforeCutoff = ($this->reportAnnual)('2028-06-01', '2028-06-15');
    expect($beforeCutoff['years'][0]['rows'][0]['interest_charged_cents'])->toBeNull()->and($beforeCutoff['years'][1]['reported_subset_cents'])->toBe(4321);
    $scoped = app(DashboardService::class)->build($this->space, 'year', viewer: $this->owner, accountId: $this->second->id)['lender_interest_ytd'];
    expect($scoped['years'][0]['owned_accounts'])->toBe(1)->and($scoped['years'][0]['rows'][0]['account_id'])->toBe($this->second->id)->and($scoped['years'][0]['reported_accounts'])->toBe(0);
    $future = ($this->reportAnnual)('2029-01-01', '2029-03-31');
    expect($future['available'])->toBeFalse()->and($future['default_year'])->toBe(2029)->and($future['years'][0]['reported_subset_cents'])->toBeNull();
});

test('source authorization excludes foreign shared accounts and inactive business memberships', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    $account = Account::create(['space_id' => $foreign->id, 'source_key' => 'fictional:foreign', 'kind' => 'credit', 'name' => 'Private foreign card']);
    $this->actingAs($this->owner)->postJson($this->annualUrl, ['kind' => 'lender_interest_ytd', 'name' => 'Foreign', 'data' => [...$this->evidence, 'account_id' => $account->id]])->assertUnprocessable();
    $cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:cash', 'name' => 'Cash', 'kind' => 'cash']);
    $this->postJson($this->annualUrl, ['kind' => 'lender_interest_ytd', 'name' => 'Cash', 'data' => [...$this->evidence, 'account_id' => $cash->id]])->assertUnprocessable();
    ($this->saveAnnual)();
    $this->actingAs($other)->postJson($this->annualUrl, ['kind' => 'lender_interest_ytd', 'name' => 'Unauthorized', 'data' => $this->evidence])->assertForbidden();
    expect(app(LenderInterestYtdService::class)->build($this->space, collect([$this->card]), Record::all(), '2028-01-01', '2028-07-25', $other))->toBeNull();
    $tenant = Tenant::create(['slug' => 'fictional-lender-business', 'name' => 'Fictional lender business']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [$business] = app(PilotService::class)->prepare($this->owner, $tenant, true, 'business');
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $bizCard = Account::create(['space_id' => $business->id, 'source_key' => 'fictional:biz', 'kind' => 'credit', 'name' => 'Business card']);
    app(RecordService::class)->save($this->owner, $business, 'lender_interest_ytd', 'Business source', [...$this->evidence, 'account_id' => $bizCard->id]);
    $this->owner->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$business->id.'/records', ['kind' => 'lender_interest_ytd', 'name' => 'Revoked', 'data' => [...$this->evidence, 'account_id' => $bizCard->id]])->assertForbidden();
});

test('annual observations leave cycle and ledger interest costs cash histories questions and future projections unchanged', function (): void {
    app(\App\Services\Trajectory\LedgerService::class)->ingest($this->card, [['id' => 'interest', 'date' => '2028-07-20', 'merchant' => 'Fictional interest', 'amount_cents' => -1111]]);
    Transaction::where('account_id', $this->card->id)->update(['category' => 'interest', 'flow' => 'expense', 'reviewed' => true]);
    app(RecordService::class)->save($this->owner, $this->space, 'interest_statement', 'Fictional cycle', ['account_id' => $this->card->id, 'period_start' => '2028-07-01', 'period_end' => '2028-07-25', 'interest_cents' => 1111, 'source' => 'Fictional cycle statement']);
    Record::create(['space_id' => $this->space->id, 'kind' => 'debt', 'name' => 'Fictional repayment projection', 'active' => true, 'data' => ['account_id' => $this->card->id, 'kind' => 'credit', 'balance_cents' => 100000, 'apr_bps' => 2000, 'payment_cents' => 10000, 'escrow_cents' => 0, 'fees_cents' => 0, 'next_due_on' => '2028-08-01', 'observed_on' => '2028-07-25', 'confirmed' => true]]);
    app(\App\Services\Trajectory\LedgerService::class)->ingest($this->card, [['id' => 'unknown-tax', 'date' => '2028-07-21', 'merchant' => 'Fictional unresolved tax cash', 'amount_cents' => -500]]);
    Transaction::where('account_id', $this->card->id)->where('amount_cents', -500)->update(['flow' => 'sales_tax_remittance', 'category' => 'taxes', 'reviewed' => true]);
    $dashboard = app(DashboardService::class);
    $before = $dashboard->build($this->space, 'year', viewer: $this->owner);
    ($this->saveAnnual)(['interest_charged_cents' => 50000]);
    $after = $dashboard->build($this->space, 'year', viewer: $this->owner);
    foreach (['summary', 'forecast', 'comparison', 'debts', 'history', 'money_flow', 'question_summary', 'decision_support', 'interest_by_account', 'interest_statements', 'household_cards', 'categories', 'economic_coverage'] as $key) {
        expect($after[$key])->toBe($before[$key]);
    }
    expect($after['summary']['spending_cents'])->toBeNull()->and($after['economic_coverage']['complete'])->toBeFalse();
    expect($after['summary']['recorded_interest_cents'])->toBe(1111)->and($after['summary']['selected_interest_cents'])->toBe(1111)
        ->and($after['lender_interest_ytd']['years'][0]['reported_subset_cents'])->toBe(50000)
        ->and($after['debts'][0]['projection']['rows'])->not->toBeEmpty();
    $partialBefore = $dashboard->build($this->space, 'custom', from: '2028-07-10', through: '2028-07-25', viewer: $this->owner);
    expect($partialBefore['summary']['selected_interest_cents'])->toBeNull();
});
