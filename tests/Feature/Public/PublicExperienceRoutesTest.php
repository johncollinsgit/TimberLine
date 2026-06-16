<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $response = $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body fb-public-body--splash"', false)
        ->assertSeeText('All of your business, in one place')
        ->assertSeeText('The Future of AI-Powered Small Business')
        ->assertSeeText('What it does')
        ->assertSeeText('Daily work')
        ->assertSeeText('Who it helps')
        ->assertSeeText('Your info')
        ->assertSeeText('Trust')
        ->assertSeeText('Pricing')
        ->assertSee('href="#everbranch-public"', false)
        ->assertSee('id="everbranch-public"', false)
        ->assertSeeText('Everbranch brings customers, work, money, materials, communication, and next steps into one intelligent app.')
        ->assertSeeText('One place to see what is happening in your business.')
        ->assertSeeText('Start with the parts of your business you want help with first.')
        ->assertSeeText('Built for real small businesses, not software teams.')
        ->assertSeeText('Bring in the information you already use.')
        ->assertSeeText('You stay in control.')
        ->assertSeeText('Find the right starting point.')
        ->assertSee('data-public-tabs', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('data-public-tab-trigger="product"', false)
        ->assertSee('data-public-tab-panel="plans"', false)
        ->assertSeeInOrder([
            'class="fb-site-nav-wrap"',
            'id="everbranch-public"',
            'id="panel-product"',
            'id="splash"',
        ], false)
        ->assertDontSee('fb-public-tabs__nav', false)
        ->assertDontSeeText('Explore Everbranch')
        ->assertDontSeeText('Choose the part of the business you want to understand first.')
        ->assertDontSeeText('Workflows')
        ->assertDontSeeText('Shopify is supported. It is not the whole product.')
        ->assertSeeText('Start as a client')
        ->assertSeeText('Login')
        ->assertSeeText('Landscaper')
        ->assertSeeText('Electrician')
        ->assertSeeText('Soap Maker')
        ->assertSee('brand/everbranch-lockup.svg?v='.$cacheTag, false)
        ->assertSee('brand/everbranch-mark.svg?v='.$cacheTag, false)
        ->assertDontSeeText('Forestry Backstage')
        ->assertDontSeeText('Backstage')
        ->assertDontSeeText('Welcome back');

    expect(substr_count($response->getContent(), 'id="splash"'))->toBe(1);

    $content = strtolower($response->getContent());
    $visibleContent = strtolower((string) preg_replace('/\s+/', ' ', strip_tags($response->getContent())));

    foreach ([
        'workflow',
        'signals',
        'advanced access',
        'review-controlled',
        'workspace',
    ] as $jargonTerm) {
        expect($visibleContent)->not->toContain($jargonTerm);
    }

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
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('class="fb-auth-body antialiased"', false)
        ->assertSee('brand/everbranch-auth.svg?v='.$cacheTag, false)
        ->assertSeeText('Welcome back');
});

test('authenticated users are still redirected away from the public home route', function (): void {
    $user = User::factory()->create([
        'role' => 'admin',
    ]);

    $this->actingAs($user)
        ->get('http://theeverbranch.com/')
        ->assertRedirect(route('dashboard', absolute: false));
});
