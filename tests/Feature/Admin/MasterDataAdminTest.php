<?php

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\MappingException;
use App\Models\OilAbbreviation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\Size;
use App\Models\User;
use App\Models\WholesaleCustomScent;
use App\Livewire\Admin\Imports\ImportExceptions;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('admin master data page renders for admin users', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeText('Admin Workspace')
        ->assertSeeText('Scent Intake')
        ->assertSeeText('Master Data');
});

test('legacy master data route redirects into the unified admin shell', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/master-data')
        ->assertRedirect(route('admin.index', ['tab' => 'master-data', 'resource' => 'scents']));
});

test('legacy scent intake entry points redirect into the unified admin shell', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/scent-intake')
        ->assertRedirect(route('admin.index', ['tab' => 'scent-intake']));

    $this->actingAs($user)
        ->get('/admin/mapping-exceptions')
        ->assertRedirect(route('admin.index', ['tab' => 'scent-intake']));
});

test('admin master data tab renders the shared grid shell', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin?tab=master-data')
        ->assertOk()
        ->assertSeeText('Normalized Catalog')
        ->assertSee('data-resources=', false)
        ->assertSee('"key":"scents"', false)
        ->assertSee('data-base-endpoint="'.url('/admin/master').'"', false);
});

test('admin scent intake tab renders inside the shared shell', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin?tab=scent-intake')
        ->assertOk()
        ->assertSeeText('Admin Workspace')
        ->assertSeeText('New Scent Intake')
        ->assertSeeText('This screen shows import exceptions that need human review.');
});

test('admin master data endpoints paginate and patch rows', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $blue = Scent::query()->create([
        'name' => 'Blue Ridge',
        'display_name' => 'Blue Ridge',
        'is_active' => true,
    ]);

    Scent::query()->create([
        'name' => 'Moss Trail',
        'display_name' => 'Moss Trail',
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/admin/master/scents?perPage=1&search=Blue&sortField=name&sortDir=asc')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.filters.search', 'Blue')
        ->assertJsonPath('meta.filters.sort', 'name')
        ->assertJsonPath('meta.filters.dir', 'asc')
        ->assertJsonPath('meta.supports_active_filter', true)
        ->assertJsonPath('meta.columns.0.key', 'name')
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.id', $blue->id)
        ->assertJsonPath('rows.0.name', 'Blue Ridge')
        ->assertJsonPath('data.0.id', $blue->id)
        ->assertJsonPath('data.0.name', 'Blue Ridge');

    $this->actingAs($user)
        ->patchJson("/admin/master/scents/{$blue->id}", [
            'display_name' => 'Blue Ridge Updated',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Blue Ridge Updated')
        ->assertJsonPath('data.is_active', false);

    expect((string) $blue->fresh()->display_name)->toBe('Blue Ridge Updated');
    expect((bool) $blue->fresh()->is_active)->toBeFalse();
});

test('admin master data endpoints can create a default row', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson('/admin/master/base-oils', [])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Base Oil');
});

test('master data seeder populates the supporting resource tables', function () {
    $this->seed(MasterDataSeeder::class);

    expect(BaseOil::query()->count())->toBeGreaterThan(0);
    expect(Blend::query()->count())->toBeGreaterThan(0);
    expect(BlendComponent::query()->count())->toBeGreaterThan(0);
    expect(OilAbbreviation::query()->count())->toBeGreaterThan(0);
    expect(ScentAlias::query()->count())->toBeGreaterThan(0);
});

test('scent intake persists a new canonical scent and exposes it through master data', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $size = Size::query()->create([
        'code' => 'intake-test-size',
        'label' => '8oz Cotton',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $order = Order::factory()->create();
    $line = OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => 'Sunrise Limited',
        'raw_variant' => '8oz Cotton',
        'ordered_qty' => 4,
        'quantity' => 4,
    ]);

    $exception = MappingException::query()->create([
        'store_key' => 'wholesale',
        'account_name' => 'Acme Boutique',
        'raw_scent_name' => 'Sunrise Limited',
        'raw_title' => 'Sunrise Limited',
        'raw_variant' => '8oz Cotton',
        'order_id' => $order->id,
        'order_line_id' => $line->id,
        'reason' => 'missing_scent',
    ]);

    $this->actingAs($user);

    Livewire::test(ImportExceptions::class)
        ->call('openModalForLine', 'line-'.$exception->id, $exception->id)
        ->set('newScentName', 'Sunrise Reserve')
        ->set('newScentDisplay', 'Sunrise Reserve')
        ->set('newScentAbbr', 'SR')
        ->set('newScentOil', 'Sunrise Reserve Blend')
        ->set('modalSizeId', $size->id)
        ->set('modalWickType', 'cotton')
        ->call('saveGroup');

    $scent = Scent::query()
        ->where('name', Scent::normalizeName('Sunrise Reserve'))
        ->firstOrFail();

    expect((string) $scent->display_name)->toBe('Sunrise Reserve');
    expect((string) $scent->abbreviation)->toBe('SR');
    expect((string) $scent->oil_reference_name)->toBe('Sunrise Reserve Blend');
    expect((bool) $scent->is_wholesale_custom)->toBeTrue();

    expect(ScentAlias::query()
        ->where('alias', 'Sunrise Limited')
        ->where('scope', 'markets')
        ->where('scent_id', $scent->id)
        ->exists())->toBeTrue();

    expect(WholesaleCustomScent::query()
        ->where('account_name', 'Acme Boutique')
        ->where('custom_scent_name', 'Sunrise Limited')
        ->where('canonical_scent_id', $scent->id)
        ->exists())->toBeTrue();

    expect((int) $line->fresh()->scent_id)->toBe((int) $scent->id);
    expect((int) $line->fresh()->size_id)->toBe((int) $size->id);
    expect((string) $line->fresh()->wick_type)->toBe('cotton');
    expect((int) $exception->fresh()->canonical_scent_id)->toBe((int) $scent->id);
    expect($exception->fresh()->resolved_at)->not->toBeNull();

    $this->actingAs($user)
        ->getJson('/admin/master/scents?search=Sunrise')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $scent->id)
        ->assertJsonPath('data.0.display_name', 'Sunrise Reserve');
});
