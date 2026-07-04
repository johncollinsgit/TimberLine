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

