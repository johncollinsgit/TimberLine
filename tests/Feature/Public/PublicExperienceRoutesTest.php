<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $response = $this->get(route('home'))
        ->assertOk()
        ->assertSee('class="fb-public-body fb-public-body--splash"', false)
        ->assertSeeText('Run the business you actually have.')
        ->assertSeeText('The Future of AI-Powered Small Business')
        ->assertSee('href="#everbranch-public"', false)
        ->assertSee('id="everbranch-public"', false)
        ->assertSeeText('Everbranch brings customers, work, money, materials, communication, and next steps into one intelligent workspace.')
        ->assertSeeText('Shopify is supported. It is not the whole product.')
        ->assertSeeText('Start as a client')
        ->assertSeeText('Login')
        ->assertSeeText('Landscaper')
        ->assertSeeText('Electrician')
        ->assertSeeText('Soap Maker')
        ->assertSee('brand/everbranch-lockup.svg?v=eb1', false)
        ->assertSee('brand/everbranch-mark.svg?v=eb1', false)
        ->assertDontSeeText('Forestry Backstage')
        ->assertDontSeeText('Backstage')
        ->assertDontSeeText('Welcome back');

    $content = strtolower($response->getContent());

    foreach ([
        'tenant',
        'slug',
        'rail',
        'canonical',
        'metadata',
        'entitlement',
        'provisioning',
        'module key',
        'commercial intent',
        'app surface',
        'lifecycle',
        'operating mode',
        'blueprint',
    ] as $forbiddenTerm) {
        expect($content)->not->toContain($forbiddenTerm);
    }
});

test('login route renders the dedicated light auth shell', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('class="fb-auth-body antialiased"', false)
        ->assertSee('brand/everbranch-auth.svg?v=eb1', false)
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
