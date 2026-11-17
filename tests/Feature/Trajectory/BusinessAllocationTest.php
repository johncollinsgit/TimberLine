<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MoneyFlowService;
use App\Services\Trajectory\PilotService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->business = function (string $slug, User $owner): Space {
        $tenant = Tenant::create(['slug' => $slug, 'name' => 'Fictional '.$slug]);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        $space = app(PilotService::class)->prepare($owner, $tenant, true, 'business')[0];
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);

        return $space;
    };
    $this->source = ($this->business)('allocation-source', $this->owner);
    $this->target = ($this->business)('allocation-target', $this->owner);
    $this->cash = Account::create(['space_id' => $this->source->id, 'source_key' => 'fictional:allocation-cash', 'name' => 'Fictional source bank', 'kind' => 'cash', 'balance_cents' => 100000]);
    app(LedgerService::class)->ingest($this->cash, [['id' => 'cost', 'date' => now()->toDateString(), 'merchant' => 'Fictional supplier', 'amount_cents' => -10001]]);
    $this->tx = Transaction::where('account_id', $this->cash->id)->firstOrFail();
    $this->tx->update(['category' => 'subscriptions', 'flow' => 'expense', 'reviewed' => true]);
    $this->url = '/trajectory/spaces/'.$this->source->id.'/transactions/'.$this->tx->id.'/split';
    $this->splits = [['space_id' => $this->source->id, 'amount_cents' => -3334], ['space_id' => $this->target->id, 'amount_cents' => -6667]];
});

test('one owner can allocate between authorized businesses without moving source bank cash or creating links', function (): void {
    $before = $this->tx->only(['space_id', 'account_id', 'source_key', 'source', 'amount_cents', 'merchant', 'flow']);
    $this->actingAs($this->owner)->postJson($this->url, ['version' => $this->tx->version, 'splits' => $this->splits])->assertOk();
    expect($this->tx->fresh()->only(array_keys($before)))->toBe($before)
        ->and($this->cash->fresh()->space_id)->toBe($this->source->id)->and($this->cash->fresh()->balance_cents)->toBe(100000)
        ->and(Allocation::where('transaction_id', $this->tx->id)->sum('amount_cents'))->toBe(-10001)
        ->and(Transaction::count())->toBe(1)->and(DB::table('trajectory_links')->count())->toBe(0);
    $entries = app(LedgerService::class)->entries($this->target);
    expect($entries)->toHaveCount(1)->and($entries[0]['amount_cents'])->toBe(-6667)
        ->and($entries[0]['merchant'])->toBe('Shared allocation')->and($entries[0]['account_id'])->toBeNull();
    $flows = app(MoneyFlowService::class);
    $source = $flows->build($this->source, collect(app(LedgerService::class)->entries($this->source)), collect([$this->cash]));
    $target = $flows->build($this->target, collect($entries), collect());
    expect($source['views']['spending']['outflow_cents'])->toBe(3334)->and($source['views']['cash']['outflow_cents'])->toBe(10001)
        ->and($target['views']['spending']['outflow_cents'])->toBe(6667)->and($target['views']['cash']['outflow_cents'])->toBeNull();
    $this->postJson($this->url, ['version' => $this->tx->version, 'splits' => $this->splits])->assertConflict();
});

test('financial access to both businesses does not authorize a non-owner to allocate between them', function (): void {
    $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    foreach ([$this->source, $this->target] as $space) {
        $admin->tenants()->attach($space->tenant_id, ['role' => 'admin', 'membership_active' => true]);
        expect(app(FinanceAccess::class)->allows($admin, $space))->toBeTrue();
    }
    $this->actingAs($admin)->postJson($this->url, ['version' => $this->tx->version, 'splits' => $this->splits])->assertUnprocessable();
    expect(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1)->and($this->tx->fresh()->version)->toBe($this->tx->version);
});

test('different business owners cannot bypass allocation ownership with a malformed household link', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->target->update(['owner_user_id' => $other->id]);
    DB::table('trajectory_links')->insert(['household_id' => $this->source->id, 'business_id' => $this->target->id, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(FinanceAccess::class)->allows($this->owner, $this->target))->toBeTrue();
    $this->actingAs($this->owner)->postJson($this->url, ['version' => $this->tx->version, 'splits' => $this->splits])->assertUnprocessable();
    expect(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1);
});

test('a foreign or disabled business remains inaccessible even when its allocation is zero', function (): void {
    $this->owner->tenants()->detach($this->target->tenant_id);
    $splits = [['space_id' => $this->source->id, 'amount_cents' => -10001], ['space_id' => $this->target->id, 'amount_cents' => 0]];
    $this->actingAs($this->owner)->postJson($this->url, ['version' => $this->tx->version, 'splits' => $splits])->assertForbidden();
    $this->owner->tenants()->attach($this->target->tenant_id, ['role' => 'owner', 'membership_active' => true]);
    $this->target->update(['enabled' => false]);
    $this->postJson($this->url, ['version' => $this->tx->version, 'splits' => $splits])->assertForbidden();
    expect(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1);
});

test('current source ownership is checked instead of a caller stale space object', function (): void {
    $other = User::factory()->create();
    $stale = $this->source->replicate()->setRawAttributes($this->source->getAttributes(), true);
    $this->source->update(['owner_user_id' => $other->id]);
    expect(fn () => app(LedgerService::class)->split($this->owner, $stale, $this->tx, $this->splits, $this->tx->version))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1);
});

test('business allocations retain sum sign uniqueness and source transaction guards', function (array $splits): void {
    $mapped = array_map(fn ($row) => ['space_id' => $row[0] === 'source' ? $this->source->id : $this->target->id, 'amount_cents' => $row[1]], $splits);
    $this->actingAs($this->owner)->postJson($this->url, ['version' => $this->tx->version, 'splits' => $mapped])->assertUnprocessable();
    expect(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1)->and(Allocation::sum('amount_cents'))->toBe(-10001);
})->with([
    'not conserved' => [[['source', -3334], ['target', -6666]]],
    'opposite sign' => [[['source', 1], ['target', -10002]]],
    'duplicate target' => [[['target', -3334], ['target', -6667]]],
]);

test('a zero bank source can only have zero allocations and cannot invent an economic expense', function (): void {
    $this->tx->update(['amount_cents' => 0]);
    Allocation::where('transaction_id', $this->tx->id)->update(['amount_cents' => 0]);
    $splits = [['space_id' => $this->source->id, 'amount_cents' => 100], ['space_id' => $this->target->id, 'amount_cents' => -100]];
    $this->actingAs($this->owner)->postJson($this->url, ['version' => $this->tx->version, 'splits' => $splits])->assertUnprocessable();
    expect(Allocation::where('transaction_id', $this->tx->id)->count())->toBe(1)
        ->and(Allocation::sum('amount_cents'))->toBe(0)->and($this->tx->fresh()->version)->toBe($this->tx->version);
    $this->postJson($this->url, ['version' => $this->tx->version, 'splits' => array_map(fn ($row) => [...$row, 'amount_cents' => 0], $splits)])->assertOk();
    expect(Allocation::where('transaction_id', $this->tx->id)->pluck('amount_cents')->all())->toBe([0, 0]);
});
