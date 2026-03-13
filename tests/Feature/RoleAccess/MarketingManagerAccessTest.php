<?php

use App\Models\User;

test('marketing manager users are redirected to marketing overview from home', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('marketing.overview', absolute: false));
});

test('admin and marketing manager can access marketing pages', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.overview'))
        ->assertOk()
        ->assertSeeText('Overview');

    $this->actingAs($marketingManager)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Customers');
});

test('non marketing roles cannot access marketing pages', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $pouring = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('marketing.overview'))
        ->assertForbidden();

    $this->actingAs($pouring)
        ->get(route('marketing.overview'))
        ->assertForbidden();
});
