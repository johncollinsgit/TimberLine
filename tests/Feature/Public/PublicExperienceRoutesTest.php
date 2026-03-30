<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('class="fb-public-body"', false)
        ->assertSeeText('Production, shipping, and wholesale in one place.')
        ->assertSee('brand/forestry-backstage-lockup.svg?v=fb2', false)
        ->assertDontSeeText('Welcome back');
});

test('login route renders the dedicated light auth shell', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('class="fb-auth-body antialiased"', false)
        ->assertSee('brand/forestry-backstage-auth.svg?v=fb2', false)
        ->assertSeeText('Welcome back');
});

test('authenticated users are still redirected away from the public home route', function (): void {
    $user = User::factory()->create([
        'role' => 'admin',
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard', absolute: false));
});
