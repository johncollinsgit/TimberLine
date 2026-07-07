<?php

use App\Models\User;
use App\Support\Auth\HomeRedirect;

test('new verified users without tenants are sent to the first-login workspace flow', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'email_verified_at' => now(),
        'is_active' => true,
        'approved_at' => now(),
    ]);

    expect(HomeRedirect::pathFor($user))->toBe(route('workspace.first-login', absolute: false));
});

test('platform operators still go to the landlord dashboard even when they have no tenants', function (): void {
    $user = User::factory()->platformAdmin()->create([
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);

    expect(HomeRedirect::pathFor($user))->toBe(route('landlord.dashboard', absolute: false));
});

test('a pouring user with a workspace membership lands in the pouring room, not workspace creation', function (): void {
    $tenant = \App\Models\Tenant::query()->create(['name' => 'Acme Co', 'slug' => 'acme']);

    $user = User::factory()->create([
        'role' => 'pouring',
        'is_active' => true,
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);

    $tenant->users()->syncWithoutDetaching([$user->id => ['role' => 'pouring']]);

    // Role decides the landing only once the user actually belongs to a workspace.
    expect(HomeRedirect::pathFor($user))->toBe(route('pouring.index', absolute: false));
});

