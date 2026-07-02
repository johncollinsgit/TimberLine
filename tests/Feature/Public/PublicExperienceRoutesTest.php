<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $response = $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body fb-public-body--splash"', false)
        ->assertSeeText('Less anxiety. Find peace. The one place to run your business.')
        ->assertSeeText('Everbranch helps small businesses organize customers, tasks, messages, files, and workflows in one simple system')
        ->assertSeeText('Home')
        ->assertSeeText('See it work')
        ->assertSeeText('Who it helps')
        ->assertSeeText('Contact')
        ->assertSee('href="#everbranch-public"', false)
        ->assertSee('id="everbranch-public"', false)
        ->assertSee('data-problem-garden', false)
        ->assertSee('data-public-product-demo', false)
        ->assertSeeText('Problem')
        ->assertSeeText('Solution')
        ->assertSee('Wholesale request -> task -> reorder follow-up', false)
        ->assertSeeText('Motion-safe version: detail captured, work organized, next step assigned, follow-up ready.')
        ->assertSeeText('Built for the messy middle of small business.')
        ->assertSeeText('Electrical & plumbing')
        ->assertSeeText('Tell us what keeps getting lost.')
        ->assertSee('data-public-tabs', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('data-public-tab-trigger="product"', false)
        ->assertSee('data-public-tab-trigger="contact"', false)
        ->assertSee('data-public-tab-panel="contact"', false)
        ->assertDontSee('data-public-tab-trigger="privacy"', false)
        ->assertDontSee('data-public-tab-panel="privacy"', false)
        ->assertDontSee('data-public-tab-trigger="integrations"', false)
        ->assertDontSee('data-public-tab-trigger="security"', false)
        ->assertDontSee('data-public-tab-trigger="plans"', false)
        ->assertSeeInOrder([
            'class="fb-site-nav-wrap"',
            'id="everbranch-public"',
            'id="panel-product"',
            'id="splash"',
        ], false)
        ->assertDontSee('fb-public-tabs__nav', false)
        ->assertDontSeeText('Explore Everbranch')
        ->assertDontSeeText('Choose the part of the business you want to understand first.')
        ->assertDontSeeText('Privacy')
        ->assertDontSeeText('Pricing')
        ->assertDontSee('href="/platform/plans"', false)
        ->assertSeeText('Request access')
        ->assertSeeText('Login')
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

test('public home route keeps trade examples available', function (): void {
    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Electrical & plumbing')
        ->assertSeeText('Job notes, estimates, parts questions, scheduling notes, and crew next steps.');
});
