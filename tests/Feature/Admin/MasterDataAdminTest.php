<?php

use App\Models\Scent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->get('/admin/master-data')
        ->assertOk()
        ->assertSeeText('Normalized Catalog')
        ->assertSee('data-resources=', false)
        ->assertSee('"key":"scents"', false)
        ->assertSee('data-base-endpoint="'.url('/admin/master').'"', false);
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
        ->getJson('/admin/master/scents?per_page=1&search=Blue')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.filters.search', 'Blue')
        ->assertJsonPath('meta.filters.sort', 'name')
        ->assertJsonPath('meta.filters.dir', 'asc')
        ->assertJsonPath('meta.supports_active_filter', true)
        ->assertJsonPath('meta.columns.0.key', 'name')
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
