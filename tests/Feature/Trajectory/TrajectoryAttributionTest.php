<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\AllocationDetail;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'attribution:checking', 'name' => 'Checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 500000]);
    $this->bankRow = ['id' => 'shop', 'date' => now()->toDateString(), 'merchant' => 'Example store', 'amount_cents' => -10001, 'category' => 'shopping'];
    app(LedgerService::class)->ingest($this->account, [$this->bankRow]);
    $this->tx = Transaction::where('account_id', $this->account->id)->firstOrFail();
    $this->attribution = app(AttributionService::class);
});

function attributionBusiness($test): \App\Models\Trajectory\Space
{
    $tenant = Tenant::create(['slug' => 'watershed-attribution', 'name' => 'Watershed']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $test->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($test->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);

    return $business;
}

test('multiple economic lines in one space preserve the unique allocation and source cents', function (): void {
    $category = $this->attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Shopping', ['key' => 'shopping', 'direction' => 'expense']);
    $child = $this->attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Clothing', ['key' => 'clothing', 'direction' => 'expense', 'parent_record_id' => $category->id]);
    $program = $this->attribution->saveDefinition($this->owner, $this->space, 'program', 'Soccer', ['key' => 'soccer']);
    $source = $this->tx->fresh()->only(['source_key', 'source', 'merchant', 'posted_on', 'amount_cents', 'account_id', 'category', 'flow']);
    $originalAllocation = Allocation::where('transaction_id', $this->tx->id)->firstOrFail()->id;
    $saved = $this->attribution->save($this->owner, $this->space, $this->tx, [
        ['space_id' => $this->space->id, 'amount_cents' => -3334, 'category' => 'shopping', 'subcategory_record_id' => $child->id, 'program_record_id' => $program->id],
        ['space_id' => $this->space->id, 'amount_cents' => -6667, 'category' => 'groceries'],
    ], $this->tx->version);
    $entries = app(LedgerService::class)->entries($this->space);
    $expanded = $this->attribution->expandEntries($this->space, $entries);
    expect($saved['version'])->toBe(2)->and(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1)
        ->and(Allocation::where('transaction_id', $this->tx->id)->first()->id)->toBe($originalAllocation)
        ->and($this->tx->fresh()->only(array_keys($source)))->toEqual($source)
        ->and($entries)->toHaveCount(1)->and($entries[0]['amount_cents'])->toBe(-10001)
        ->and($expanded)->toHaveCount(2)->and(array_sum(array_column($expanded, 'amount_cents')))->toBe(-10001)
        ->and($expanded[0]['category'])->toBe('clothing')->and($expanded[0]['parent_category'])->toBe('shopping')
        ->and($expanded[0]['source_transaction_id'])->toBe($this->tx->id)
        ->and($expanded[0]['attribution_detail_id'])->not->toBeNull()
        ->and($this->attribution->expandEntries($this->space, $entries, $program->id))->toHaveCount(1);
});

test('household funded reimbursed program costs remain business expenses without fake household receipts', function (): void {
    $business = attributionBusiness($this);
    $program = $this->attribution->saveDefinition($this->owner, $business, 'program', 'Soccer', ['key' => 'soccer']);
    $this->attribution->save($this->owner, $this->space, $this->tx, [
        ['space_id' => $business->id, 'amount_cents' => -10001, 'category' => 'shopping', 'program_record_id' => $program->id,
            'settlement_status' => 'reimbursed', 'settlement_note' => 'Owner confirmed reimbursed previously.'],
    ], $this->tx->version);
    $household = app(LedgerService::class)->entries($this->space);
    $businessEntries = app(LedgerService::class)->entries($business);
    expect(Transaction::count())->toBe(1)->and(Allocation::sum('amount_cents'))->toBe(-10001)
        ->and($household[0]['amount_cents'])->toBe(0)->and($businessEntries[0]['amount_cents'])->toBe(-10001)
        ->and($businessEntries[0]['merchant'])->toBe('Shared allocation')->and($businessEntries[0]['account_id'])->toBeNull()
        ->and($businessEntries[0]['allocation_details'][0]['settlement_note'])->toBeNull()
        ->and($this->attribution->expandEntries($business, $businessEntries, $program->id)[0]['settlement_status'])->toBe('reimbursed');
    $stranger = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $this->attribution->describe($stranger, $this->space, $this->tx))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('stale versions wrong signs totals and foreign dimensions fail without allocation changes', function (): void {
    $business = attributionBusiness($this);
    $foreignProgram = $this->attribution->saveDefinition($this->owner, $business, 'program', 'Co-op', ['key' => 'co_op']);
    $before = Allocation::all()->toArray();
    foreach ([
        [['space_id' => $this->space->id, 'amount_cents' => -10000, 'category' => 'shopping']],
        [['space_id' => $this->space->id, 'amount_cents' => -10001, 'category' => 'shopping', 'program_record_id' => $foreignProgram->id]],
        [['space_id' => $this->space->id, 'amount_cents' => -10002, 'category' => 'shopping'], ['space_id' => $this->space->id, 'amount_cents' => 1, 'category' => 'shopping']],
    ] as $lines) {
        expect(fn () => $this->attribution->save($this->owner, $this->space, $this->tx, $lines, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    expect(fn () => $this->attribution->save($this->owner, $this->space, $this->tx, [['space_id' => $this->space->id, 'amount_cents' => -10001, 'category' => 'shopping']], 99))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Allocation::all()->toArray())->toEqual($before)->and(AllocationDetail::count())->toBe(0)->and($this->tx->fresh()->version)->toBe(1);
});

test('undo restores the previous allocations and retains all detail history', function (): void {
    $original = $this->tx->fresh();
    $saved = $this->attribution->save($this->owner, $this->space, $this->tx, [['space_id' => $this->space->id, 'amount_cents' => -10001, 'category' => 'groceries']], 1);
    $this->attribution->undo($this->owner, $this->space, $this->tx, $saved['version'], $saved['event_id']);
    expect($this->tx->fresh()->version)->toBe(3)->and($this->tx->fresh()->reviewed)->toBe($original->reviewed)
        ->and(AllocationDetail::count())->toBe(1)->and(AllocationDetail::where('active', true)->count())->toBe(0)
        ->and(Allocation::sum('amount_cents'))->toBe(-10001)->and($this->attribution->expandEntries($this->space, app(LedgerService::class)->entries($this->space))[0]['category'])->toBe('shopping');
    expect(fn () => $this->attribution->undo($this->owner, $this->space, $this->tx, 3, $saved['event_id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('unchanged import retains detail but amended source invalidates without erasing attribution history', function (): void {
    $saved = $this->attribution->save($this->owner, $this->space, $this->tx, [['space_id' => $this->space->id, 'amount_cents' => -10001, 'category' => 'groceries']], 1);
    app(LedgerService::class)->ingest($this->account, [$this->bankRow]);
    expect(AllocationDetail::where('active', true)->count())->toBe(1)->and($this->tx->fresh()->version)->toBe($saved['version']);
    app(LedgerService::class)->ingest($this->account, [[...$this->bankRow, 'amount_cents' => -11001]]);
    expect(AllocationDetail::count())->toBe(1)->and(AllocationDetail::where('active', true)->count())->toBe(0)
        ->and($this->tx->fresh()->reviewed)->toBeFalse()->and(Allocation::sum('amount_cents'))->toBe(-11001)
        ->and(AllocationDetail::first()->invalidation_reason)->toBe('bank_source_changed');
    expect(fn () => $this->attribution->undo($this->owner, $this->space, $this->tx, $this->tx->fresh()->version, $saved['event_id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('versioned category and program catalogs retain labels and reject stale or foreign parent edits', function (): void {
    $category = $this->attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Home', ['key' => 'home_items', 'direction' => 'expense']);
    $renamed = $this->attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Home supplies', ['key' => 'home_items', 'direction' => 'expense'], $category, 1);
    expect($renamed->version)->toBe(2)->and($this->attribution->categoryDefinitions($this->space)[0]['name'])->toBe('Home supplies');
    expect(fn () => $this->attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Old edit', ['key' => 'home_items', 'direction' => 'expense'], $category, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->attribution->saveDefinition($this->owner, $this->space, 'category_definition', 'Invalid child', ['key' => 'child', 'direction' => 'income', 'parent_record_id' => $category->id]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('program budgets validate owned active references and signed reviewed rollover openings', function (): void {
    $program = $this->attribution->saveDefinition($this->owner, $this->space, 'program', 'Soccer', ['key' => 'soccer']);
    $data = ['category' => 'shopping', 'monthly_cents' => 50000, 'purpose' => 'spending', 'effective_on' => '2026-01-01', 'reviewed' => true,
        'program_record_id' => $program->id, 'rollover' => true, 'rollover_start_month' => '2026-01', 'opening_rollover_cents' => -12500];
    $record = app(RecordService::class)->save($this->owner, $this->space, 'budget', 'Soccer clothing', $data);
    expect($record->data['program_record_id'])->toBe($program->id)->and($record->data['opening_rollover_cents'])->toBe(-12500);
    $program->update(['active' => false]);
    expect(fn () => app(RecordService::class)->validate($this->space, 'budget', $data))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('attribution endpoints authorize the source and expose exact editable detail and undo versions', function (): void {
    $base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner)->postJson($base.'/attribution/definitions', ['kind' => 'program', 'name' => 'Soccer', 'key' => 'soccer'])
        ->assertOk()->assertJsonPath('record.kind', 'program');
    $this->getJson($base.'/attribution/definitions')->assertOk()->assertJsonPath('programs.0.key', 'soccer');
    $url = $base.'/transactions/'.$this->tx->id.'/attribution';
    $saved = $this->putJson($url, ['version' => 1, 'lines' => [['space_id' => $this->space->id, 'category' => 'groceries', 'amount_cents' => -10001]]])
        ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('cash_changed', false)->json();
    $detail = $this->getJson($url)->assertOk()->assertJsonPath('version', 2)->json('lines.0');
    expect($detail['detail_id'])->toBeInt();
    $this->postJson($url.'/undo', ['version' => 2, 'event_id' => $saved['event_id']])->assertOk()->assertJsonPath('version', 3);
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->getJson($url)->assertForbidden();
});

test('pending program attribution survives proven stable pending to posted identity promotion once', function (): void {
    $row = ['id' => 'pending-details', 'date' => now()->toDateString(), 'merchant' => 'Pending example', 'amount_cents' => -2500, 'pending' => true, 'category' => 'shopping'];
    app(LedgerService::class)->ingest($this->account, [$row]);
    $tx = Transaction::where('source_key', 'attribution:checking:pending-details')->firstOrFail();
    $program = $this->attribution->saveDefinition($this->owner, $this->space, 'program', 'Soccer', ['key' => 'soccer']);
    $this->attribution->save($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -2500, 'category' => 'shopping', 'program_record_id' => $program->id]], 1);
    expect(app(LedgerService::class)->entries($this->space, ids: [$tx->id]))->toBe([]);
    $posted = [...$row, 'id' => 'posted-details', 'pending_id' => 'pending-details', 'pending' => false];
    app(LedgerService::class)->ingest($this->account, [$posted]);
    app(LedgerService::class)->ingest($this->account, [$posted]);
    $entries = app(LedgerService::class)->entries($this->space, ids: [$tx->id]);
    expect(Transaction::where('account_id', $this->account->id)->count())->toBe(2)
        ->and($tx->fresh()->source_key)->toBe('attribution:checking:posted-details')->and($tx->fresh()->pending)->toBeFalse()
        ->and(AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->count())->toBe(1)
        ->and($this->attribution->expandEntries($this->space, $entries, $program->id)[0]['amount_cents'])->toBe(-2500);
});

test('pending costs cannot attest settlement and amended posting amounts invalidate program detail', function (): void {
    $row = ['id' => 'pending-amended', 'date' => now()->toDateString(), 'merchant' => 'Pending example', 'amount_cents' => -2500, 'pending' => true, 'category' => 'shopping'];
    app(LedgerService::class)->ingest($this->account, [$row]);
    $tx = Transaction::where('source_key', 'attribution:checking:pending-amended')->firstOrFail();
    $business = attributionBusiness($this);
    expect(fn () => $this->attribution->save($this->owner, $this->space, $tx, [['space_id' => $business->id, 'amount_cents' => -2500, 'category' => 'shopping', 'settlement_status' => 'reimbursed', 'settlement_note' => 'Not actually settled.']], 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->attribution->save($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -2500, 'category' => 'groceries']], 1);
    app(LedgerService::class)->ingest($this->account, [[...$row, 'id' => 'posted-amended', 'pending_id' => 'pending-amended', 'pending' => false, 'amount_cents' => -2750]]);
    expect($tx->fresh()->amount_cents)->toBe(-2750)->and($tx->fresh()->reviewed)->toBeFalse()
        ->and(AllocationDetail::where('transaction_id', $tx->id)->count())->toBe(1)
        ->and(AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->count())->toBe(0);
});

test('personal app keeps its household scope while authorized attribution offers linked businesses without balances', function (): void {
    $business = attributionBusiness($this);
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($stranger, null, true);
    $this->actingAs($this->owner)->get('/trajectory/personal/')->assertOk()->assertViewHas('spaces', fn ($spaces) => $spaces->every(fn ($space) => $space->kind === 'household'));
    $body = $this->getJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$this->tx->id.'/attribution')->assertOk()->json();
    $targets = collect($body['target_spaces']);
    expect($targets->pluck('id')->all())->toContain($this->space->id, $business->id)->not->toContain($foreign->id);
    foreach ($targets as $target) {
        expect(array_keys($target))->toBe(['id', 'name', 'kind']);
    }
});

test('original ledger remains usable before the additive attribution schema is installed', function (): void {
    \Illuminate\Support\Facades\Schema::drop('trajectory_allocation_details');
    expect($this->attribution->available())->toBeFalse();
    app(LedgerService::class)->ingest($this->account, [[...$this->bankRow, 'amount_cents' => -11001]]);
    $tx = $this->tx->fresh();
    app(LedgerService::class)->classify($this->owner, $this->space, $tx, ['version' => $tx->version, 'category' => 'groceries', 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false]);
    $entries = app(LedgerService::class)->entries($this->space);
    expect($entries)->toHaveCount(1)->and($entries[0]['amount_cents'])->toBe(-11001)->and($entries[0]['category'])->toBe('groceries')
        ->and($entries[0]['allocation_details'])->toBe([])->and($entries[0]['attribution_status'])->toBe('unavailable');
    $pending = ['id' => 'before-schema-pending', 'date' => now()->toDateString(), 'merchant' => 'Fictional pending', 'amount_cents' => -700, 'pending' => true];
    app(LedgerService::class)->ingest($this->account, [$pending]);
    app(LedgerService::class)->ingest($this->account, [[...$pending, 'id' => 'before-schema-posted', 'pending_id' => 'before-schema-pending', 'pending' => false]]);
    expect(Transaction::where('account_id', $this->account->id)->count())->toBe(2);
    $this->actingAs($this->owner)->putJson('/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id.'/attribution',
        ['version' => $tx->fresh()->version, 'lines' => [['space_id' => $this->space->id, 'category' => 'groceries', 'amount_cents' => -11001]]])->assertStatus(503);
    $migration = require database_path('migrations/2026_11_18_120000_create_trajectory_allocation_details.php');
    $migration->up();
    expect($this->attribution->available())->toBeTrue();
    $this->attribution->save($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'category' => 'groceries', 'amount_cents' => -11001]], $tx->fresh()->version);
    expect(AllocationDetail::where('active', true)->count())->toBe(1)->and(Allocation::sum('amount_cents'))->toBe(-11701);
});

test('economic projection authorization queries scale with distinct spaces rather than history rows', function (): void {
    $rows = [];
    for ($index = 1; $index <= 60; $index++) {
        $rows[] = [...$this->bankRow, 'id' => 'history-'.$index, 'amount_cents' => -1000 - $index];
    }
    app(LedgerService::class)->ingest($this->account, $rows);
    $entries = app(LedgerService::class)->entries($this->space);
    $factsBefore = Transaction::where('account_id', $this->account->id)->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    $connection = \Illuminate\Support\Facades\DB::connection();
    $connection->enableQueryLog();
    try {
        $connection->flushQueryLog();
        $single = $this->attribution->expandEntries($this->space, [$entries[0]], viewer: $this->owner);
        $singleQueries = count($connection->getQueryLog());
        $connection->flushQueryLog();
        $history = $this->attribution->expandEntries($this->space, $entries, viewer: $this->owner);
        $historyQueries = count($connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
    expect($history)->toHaveCount(61)->and($historyQueries)->toBeLessThanOrEqual($singleQueries + 1)
        ->and($single[0]['attribution_editable'])->toBeTrue()
        ->and(collect($history)->every(fn ($entry) => $entry['attribution_editable'] === true))->toBeTrue()
        ->and(array_sum(array_column($history, 'amount_cents')))->toBe(array_sum(array_column($entries, 'amount_cents')))
        ->and(Transaction::where('account_id', $this->account->id)->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all())->toBe($factsBefore);
});

test('economic projection rechecks revoked business access on the next invocation', function (): void {
    $business = attributionBusiness($this);
    $program = $this->attribution->saveDefinition($this->owner, $business, 'program', 'Soccer', ['key' => 'soccer']);
    $this->attribution->save($this->owner, $this->space, $this->tx,
        [['space_id' => $business->id, 'amount_cents' => -10001, 'category' => 'shopping', 'program_record_id' => $program->id]], $this->tx->version);
    $entries = app(LedgerService::class)->entries($business);
    $authorized = $this->attribution->expandEntries($business, $entries, $program->id, $this->owner);
    expect($authorized[0]['attribution_editable'])->toBeTrue()->and($authorized[0]['source_space_id'])->toBe($this->space->id);
    $this->space->update(['enabled' => false]);
    $sourceRevoked = $this->attribution->expandEntries($business, $entries, $program->id, $this->owner);
    expect($sourceRevoked[0]['attribution_editable'])->toBeFalse()->and($sourceRevoked[0]['source_space_id'])->toBeNull();
    $this->space->update(['enabled' => true]);
    $this->owner->tenants()->updateExistingPivot($business->tenant_id, ['membership_active' => false]);
    $revoked = $this->attribution->expandEntries($business, $entries, $program->id, $this->owner);
    expect($revoked[0]['attribution_editable'])->toBeFalse()->and($revoked[0]['source_space_id'])->toBeNull()
        ->and($revoked[0]['merchant'])->toBe('Shared allocation')->and($revoked[0]['account_id'])->toBeNull()
        ->and($revoked[0]['amount_cents'])->toBe(-10001);
});
