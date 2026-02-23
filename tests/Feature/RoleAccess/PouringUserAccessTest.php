<?php

use App\Models\User;

test('pouring users are redirected to pouring room after login and from home', function () {
    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);

    $login = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $login->assertSessionHasNoErrors()
        ->assertRedirect(route('pouring.index', absolute: false));

    $this->assertAuthenticatedAs($user);

    $this->get(route('home'))
        ->assertRedirect(route('pouring.index', absolute: false));
});

test('pouring users can access pouring pages but not shipping admin retail markets dashboards', function () {
    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get(route('pouring.index'))->assertOk();
    $this->get(route('pouring.stack', ['channel' => 'retail']))->assertOk();
    $this->get(route('pouring.requests'))->assertOk();

    foreach ([
        route('dashboard'),
        route('shipping.orders'),
        route('admin.index'),
        route('retail.plan'),
        route('analytics.index'),
        route('inventory.index'),
        route('events.index'),
        route('markets.lists.index'),
    ] as $url) {
        $this->get($url)->assertForbidden();
    }
});
