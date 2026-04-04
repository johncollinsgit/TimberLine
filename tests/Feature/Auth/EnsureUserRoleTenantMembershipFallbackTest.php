<?php

use App\Models\Tenant;
use App\Models\User;

test('marketing customers route allows tenant manager membership even when global role is not marketing-enabled', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $this->actingAs($user)
        ->get(route('marketing.customers', ['tenant' => $tenant->id]))
        ->assertOk()
        ->assertSeeText('Customers');
});

test('marketing customers route treats tenant owner membership as admin-equivalent for role checks', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry-owner',
    ]);

    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('marketing.customers', ['tenant' => $tenant->id]))
        ->assertOk()
        ->assertSeeText('Customers');
});

test('marketing customers route still forbids tenant members without allowed tenant role', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry-forbidden',
    ]);

    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'pouring']);

    $this->actingAs($user)
        ->get(route('marketing.customers', ['tenant' => $tenant->id]))
        ->assertForbidden();
});

test('blank global role keeps legacy admin access on non-tenant role-gated routes', function (): void {
    $user = User::factory()->create([
        'role' => '',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});
