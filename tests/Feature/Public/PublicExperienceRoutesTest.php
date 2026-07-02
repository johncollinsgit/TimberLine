<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $response = $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body fb-public-body--showcase"', false)
        ->assertSeeText('Give your company an app your team will actually use.')
        ->assertSeeText('Small-business work, finally in one place')
        ->assertSeeText('The Problem')
        ->assertSeeText('The Plan')
        ->assertSeeText('See It Work')
        ->assertSeeText('Who It Helps')
        ->assertSeeText('Contact')
        ->assertSee('href="#everbranch-public"', false)
        ->assertSee('id="everbranch-public"', false)
        ->assertSee('id="product-theater"', false)
        ->assertSee('data-product-demo-jump', false)
        ->assertSeeText('Add a customer')
        ->assertSeeText('Assign your team')
        ->assertSeeText('Create an invoice')
        ->assertSeeText('See net profit')
        ->assertSeeText('Example workspace')
        ->assertSeeText('Different businesses. Same mess.')
        ->assertSeeText('Give the work a home, then move it forward.')
        ->assertSeeText('Bring the messy version. We will help shape the clean one.')
        ->assertSee('data-public-tabs', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('data-public-tab-trigger="demo"', false)
        ->assertSee('data-public-tab-trigger="contact"', false)
        ->assertSee('data-public-tab-panel="contact"', false)
        ->assertDontSee('data-public-tab-trigger="integrations"', false)
        ->assertDontSee('data-public-tab-trigger="security"', false)
        ->assertDontSee('data-public-tab-panel="integrations"', false)
        ->assertDontSee('data-public-tab-panel="security"', false)
        ->assertDontSee('data-public-tab-trigger="plans"', false)
        ->assertDontSee('data-public-tab-panel="plans"', false)
        ->assertDontSee('id="tab-integrations"', false)
        ->assertDontSee('id="tab-security"', false)
        ->assertDontSee('id="panel-integrations"', false)
        ->assertDontSee('id="panel-security"', false)
        ->assertDontSee('id="tab-plans"', false)
        ->assertDontSee('id="panel-plans"', false)
        ->assertSeeInOrder([
            'class="fb-site-nav-wrap"',
            'id="everbranch-public"',
            'id="panel-demo"',
            'id="product-theater"',
        ], false)
        ->assertDontSee('fb-public-tabs__nav', false)
        ->assertDontSeeText('Explore Everbranch')
        ->assertDontSeeText('Choose the part of the business you want to understand first.')
        ->assertDontSeeText('Trust')
        ->assertDontSeeText('Pricing')
        ->assertDontSeeText('Find the right starting point.')
        ->assertDontSee('href="/platform/plans"', false)
        ->assertDontSeeText('Shopify is supported. It is not the whole product.')
        ->assertSeeText('Request access')
        ->assertSeeText('Login')
        ->assertSeeText('Retail & product brands')
        ->assertDontSeeText('Electrician')
        ->assertSeeText('Service businesses')
        ->assertSee('brand/everbranch-lockup.svg?v='.$cacheTag, false)
        ->assertSee('brand/everbranch-mark.svg?v='.$cacheTag, false)
        ->assertDontSeeText('Forestry Backstage')
        ->assertDontSeeText('Backstage')
        ->assertDontSeeText('Welcome back');

    expect(substr_count($response->getContent(), 'id="splash"'))->toBe(1);

    $content = strtolower($response->getContent());
    $visibleContent = strtolower((string) preg_replace('/\s+/', ' ', strip_tags($response->getContent())));

    foreach ([
        'signals',
        'advanced access',
        'review-controlled',
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

test('public home route can restore electrician profile when the customer tutorial flag is enabled', function (): void {
    config()->set('features.customer_electrician_tutorial', true);

    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Electrician');
});
