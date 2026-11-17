<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-16 17:00:00', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->makeBusiness = function (string $slug) {
        $tenant = Tenant::create(['slug' => $slug, 'name' => 'Fictional '.ucfirst($slug)]);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
        app()->forgetInstance(TenantModuleAccessResolver::class);

        return $business;
    };
    $this->business = ($this->makeBusiness)('juniper-workshop');
    $this->space->refresh();
    $this->source = [
        'business_space_id' => $this->business->id, 'source_type' => 'merchant',
        'source_value' => 'Juniper Workshop Payroll', 'support_kind' => 'net_payroll',
        'source' => 'Fictional payroll receipt reviewed by the household.', 'confirmed' => true,
    ];
    $this->url = '/trajectory/spaces/'.$this->space->id.'/records';
    $this->card = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:household-card', 'name' => 'Fictional household card', 'kind' => 'credit', 'balance_cents' => 87654, 'observed_at' => now()]);
    $this->observation = ['account_id' => $this->card->id, 'balance_cents' => 0, 'observed_on' => '2026-01-01', 'source' => 'Fictional opening statement.', 'reviewed' => true];
});

test('support source saves normalized exact merchant evidence with an audited idempotent replay', function (): void {
    $payload = ['kind' => 'company_support_source', 'name' => 'Juniper wages', 'data' => [...$this->source, 'business_space_id' => (string) $this->business->id, 'source_value' => '  JUNIPER   Workshop Payroll  ']];
    $saved = $this->actingAs($this->owner)->postJson($this->url, $payload)->assertOk();
    $record = Record::findOrFail($saved->json('id'));
    expect($record->data['business_space_id'])->toBe($this->business->id)
        ->and($record->data['source_value'])->toBe('juniper workshop payroll')
        ->and($record->data['confirmed'])->toBeTrue();
    $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('id', $record->id);
    expect(Record::where('kind', 'company_support_source')->count())->toBe(1)
        ->and($record->fresh()->version)->toBe(1)
        ->and(Event::where('action', 'save_company_support_source')->where('record_id', $record->id)->count())->toBe(1);
});

test('support category must belong to this household and empty merchant normalization is rejected', function (): void {
    $this->space->update(['settings' => [...$this->space->settings, 'income_categories' => ['juniper_wages' => 'Juniper wages']]]);
    $category = [...$this->source, 'source_type' => 'category', 'source_value' => 'juniper_wages'];
    $this->actingAs($this->owner)->postJson($this->url, ['kind' => 'company_support_source', 'name' => 'Category evidence', 'data' => $category])->assertOk();
    foreach ([['source_type' => 'category', 'source_value' => 'foreign_wages'], ['source_value' => " \t\n "], ['source_type' => 'regex'], ['support_kind' => 'gross_payroll']] as $change) {
        $this->postJson($this->url, ['kind' => 'company_support_source', 'name' => 'Invalid source', 'data' => [...$this->source, ...$change]])->assertUnprocessable();
    }
    expect(Record::where('kind', 'company_support_source')->count())->toBe(1);
});

test('support source requires a household and an explicitly linked real business', function (): void {
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->business->id.'/records', ['kind' => 'company_support_source', 'name' => 'Wrong placement', 'data' => $this->source])->assertUnprocessable();
    $this->postJson($this->url, ['kind' => 'company_support_source', 'name' => 'Not a business', 'data' => [...$this->source, 'business_space_id' => $this->space->id]])->assertUnprocessable();
    DB::table('trajectory_links')->where('household_id', $this->space->id)->where('business_id', $this->business->id)->delete();
    $this->postJson($this->url, ['kind' => 'company_support_source', 'name' => 'Unlinked', 'data' => $this->source])->assertUnprocessable();
    expect(Record::where('kind', 'company_support_source')->count())->toBe(0);
});

test('support source checks current business financial role membership and enablement', function (): void {
    $payload = ['kind' => 'company_support_source', 'name' => 'Juniper wages', 'data' => $this->source];
    $this->actingAs($this->owner);
    $this->owner->tenants()->updateExistingPivot($this->business->tenant_id, ['role' => 'member']);
    $this->postJson($this->url, $payload)->assertForbidden();
    $this->owner->tenants()->updateExistingPivot($this->business->tenant_id, ['role' => 'owner', 'membership_active' => false]);
    $this->postJson($this->url, $payload)->assertForbidden();
    $this->owner->tenants()->updateExistingPivot($this->business->tenant_id, ['membership_active' => true]);
    $this->business->update(['enabled' => false]);
    $this->postJson($this->url, $payload)->assertForbidden();
    expect(Record::where('kind', 'company_support_source')->count())->toBe(0);
});

test('shared household access cannot create a company mapping without business finance access', function (): void {
    $other = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $payload = ['kind' => 'company_support_source', 'name' => 'Private evidence', 'data' => $this->source];
    $this->actingAs($other)->postJson($this->url, $payload)->assertForbidden();
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson($this->url, $payload)->assertForbidden();
    expect(Record::where('kind', 'company_support_source')->count())->toBe(0);
});

test('support source collisions require versioned corrections including cross-company mappings', function (): void {
    $service = app(RecordService::class);
    $record = $service->save($this->owner, $this->space, 'company_support_source', 'Juniper wages', $this->source);
    $second = ($this->makeBusiness)('alder-studio');
    foreach ([['support_kind' => 'owner_draw'], ['business_space_id' => $second->id], ['source' => 'A changed attribution.']] as $change) {
        $this->actingAs($this->owner)->postJson($this->url, ['kind' => 'company_support_source', 'name' => 'Juniper wages', 'data' => [...$this->source, 'source_value' => 'JUNIPER  WORKSHOP PAYROLL', ...$change]])->assertConflict();
    }
    $payload = ['kind' => 'company_support_source', 'name' => 'Corrected wages', 'data' => [...$this->source, 'support_kind' => 'other_support'], 'version' => 1];
    $this->patchJson($this->url.'/'.$record->id, $payload)->assertOk()->assertJsonPath('version', 2);
    $this->patchJson($this->url.'/'.$record->id, $payload)->assertConflict();
    expect($record->fresh()->data['support_kind'])->toBe('other_support')
        ->and(Event::where('action', 'save_company_support_source')->where('record_id', $record->id)->count())->toBe(2)
        ->and(Record::where('kind', 'company_support_source')->count())->toBe(1);
});

test('foreign household source records neither block creation nor permit cross-household edits', function (): void {
    $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$foreignSpace] = app(PilotService::class)->prepare($other, null, true);
    $foreign = Record::create(['space_id' => $foreignSpace->id, 'kind' => 'company_support_source', 'name' => 'Foreign evidence', 'data' => [...$this->source, 'source_value' => 'juniper workshop payroll'], 'active' => true, 'version' => 1]);
    $payload = ['kind' => 'company_support_source', 'name' => 'Juniper wages', 'data' => $this->source];
    $this->actingAs($this->owner)->postJson($this->url, $payload)->assertOk();
    $this->patchJson($this->url.'/'.$foreign->id, [...$payload, 'version' => 1])->assertNotFound();
    expect($foreign->fresh()->name)->toBe('Foreign evidence')->and($foreign->fresh()->version)->toBe(1);
});

test('budget date windows are inclusive and owner support is an explicit business-only purpose', function (): void {
    $budget = ['category' => 'groceries', 'monthly_cents' => 12345, 'purpose' => 'spending', 'effective_on' => '2027-01-01', 'reviewed' => true];
    $this->actingAs($this->owner)->postJson($this->url, ['kind' => 'budget', 'name' => 'Legacy target', 'data' => $budget])->assertOk();
    $this->postJson($this->url, ['kind' => 'budget', 'name' => 'One day target', 'data' => [...$budget, 'ends_on' => '2027-01-01']])->assertOk();
    $this->postJson($this->url, ['kind' => 'budget', 'name' => 'Invalid range', 'data' => [...$budget, 'ends_on' => '2026-12-31']])->assertUnprocessable();
    $this->postJson($this->url, ['kind' => 'budget', 'name' => 'Household owner support', 'data' => [...$budget, 'purpose' => 'owner_support']])->assertUnprocessable();
    $this->postJson('/trajectory/spaces/'.$this->business->id.'/records', ['kind' => 'budget', 'name' => 'Planned owner support', 'data' => [...$budget, 'purpose' => 'owner_support', 'ends_on' => '2027-03-31']])->assertOk()->assertJsonPath('data.ends_on', '2027-03-31')->assertJsonPath('data.purpose', 'owner_support');
});

test('payment notices preserve optional evidence type and reject unrecognized types', function (): void {
    $notice = ['account_id' => $this->card->id, 'amount_cents' => 1234, 'due_on' => '2026-11-28', 'observed_on' => '2026-11-16', 'source' => 'Fictional lender notice.', 'confirmed' => true];
    $this->actingAs($this->owner)->postJson($this->url, ['kind' => 'payment_notice', 'name' => 'Legacy notice', 'data' => $notice])->assertOk();
    foreach (['minimum_due', 'scheduled_payment'] as $type) {
        $this->postJson($this->url, ['kind' => 'payment_notice', 'name' => 'Typed notice', 'data' => [...$notice, 'payment_type' => $type]])->assertOk()->assertJsonPath('data.payment_type', $type);
    }
    $this->postJson($this->url, ['kind' => 'payment_notice', 'name' => 'Invalid notice', 'data' => [...$notice, 'payment_type' => 'guaranteed_remaining_due']])->assertUnprocessable();
});

test('debt balance evidence accepts zero and replays once without changing the ledger or current balance', function (): void {
    $payload = ['kind' => 'debt_balance_observation', 'name' => 'Opening balance', 'data' => $this->observation];
    $saved = $this->actingAs($this->owner)->postJson($this->url, $payload)->assertOk()->assertJsonPath('data.balance_cents', 0);
    $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('id', $saved->json('id'));
    $this->postJson($this->url, [...$payload, 'data' => [...$this->observation, 'observed_on' => '2026-11-16', 'balance_cents' => 87654]])->assertOk();
    expect(Record::where('kind', 'debt_balance_observation')->count())->toBe(2)
        ->and(Event::where('action', 'save_debt_balance_observation')->count())->toBe(2)
        ->and($this->card->fresh()->balance_cents)->toBe(87654)
        ->and(Transaction::count())->toBe(0);
});

test('same account and balance date conflicts require a versioned debt evidence correction', function (): void {
    $record = app(RecordService::class)->save($this->owner, $this->space, 'debt_balance_observation', 'Opening balance', $this->observation);
    $payload = ['kind' => 'debt_balance_observation', 'name' => 'Opening balance', 'data' => [...$this->observation, 'balance_cents' => 12345]];
    $this->actingAs($this->owner)->postJson($this->url, $payload)->assertConflict();
    $this->patchJson($this->url.'/'.$record->id, [...$payload, 'version' => 1])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('data.balance_cents', 12345);
    $this->patchJson($this->url.'/'.$record->id, [...$payload, 'version' => 1])->assertConflict();
    expect(Record::where('kind', 'debt_balance_observation')->count())->toBe(1)->and($this->card->fresh()->balance_cents)->toBe(87654);
});

test('debt evidence accepts business loans but rejects cash foreign future and negative balances', function (): void {
    $cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:cash', 'name' => 'Fictional cash', 'kind' => 'cash']);
    $loan = Account::create(['space_id' => $this->business->id, 'source_key' => 'fictional:business-loan', 'name' => 'Fictional business loan', 'kind' => 'loan']);
    $this->actingAs($this->owner);
    foreach ([['balance_cents' => -1], ['observed_on' => '2026-11-17'], ['account_id' => $cash->id], ['account_id' => $loan->id]] as $change) {
        $this->postJson($this->url, ['kind' => 'debt_balance_observation', 'name' => 'Invalid debt evidence', 'data' => [...$this->observation, ...$change]])->assertUnprocessable();
    }
    $this->postJson('/trajectory/spaces/'.$this->business->id.'/records', ['kind' => 'debt_balance_observation', 'name' => 'Business opening evidence', 'data' => [...$this->observation, 'account_id' => $loan->id, 'reviewed' => false]])->assertOk();
    expect(Record::where('kind', 'debt_balance_observation')->count())->toBe(1);
});
