<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $response = $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body fb-public-body--splash"', false)
        ->assertSeeText('Less Problems. More peace. The one place to run your business.')
        ->assertSeeText('Everbranch helps small businesses organize customers, tasks, messages, files, and workflows in one simple system')
        ->assertSeeText('Home')
        ->assertSeeText('See it work')
        ->assertSeeText('Who it helps')
        ->assertSeeText('Contact')
        ->assertSee('href="#everbranch-public"', false)
        ->assertSee('id="everbranch-public"', false)
        ->assertDontSee('data-problem-garden', false)
        ->assertSee('data-public-phone-demo', false)
        ->assertSee('data-phone-tab="home"', false)
        ->assertSee('data-phone-tab="work"', false)
        ->assertSee('data-phone-tab="branches"', false)
        ->assertSee('data-phone-tab="account"', false)
        ->assertSee('data-phone-panel="work"', false)
        ->assertSee('data-phone-panel="branches"', false)
        ->assertSee('data-phone-panel="account"', false)
        ->assertSeeText('Marketing lift')
        ->assertSeeText('$4,280')
        ->assertSeeText('made from Everbranch marketing this month')
        ->assertSeeText('Completed work')
        ->assertSeeText('$18,640')
        ->assertSeeText('jobs completed in the last 30 days')
        ->assertSeeText('Message customer')
        ->assertSeeText('Job complete')
        ->assertSeeText('Green check, done')
        ->assertSeeText('Rewards')
        ->assertSeeText('Birthday')
        ->assertSeeText('Launch Partner')
        ->assertSeeText('Job-complete messages')
        ->assertSeeText('New launch tiers')
        ->assertSeeText('Launch Partner')
        ->assertSeeText('$59/mo for 6 months')
        ->assertSee('data-public-product-demo', false)
        ->assertSeeText('Problem')
        ->assertSeeText('The solution')
        ->assertSeeText('Retail')
        ->assertSeeText('Trades')
        ->assertSeeText('Projects')
        ->assertSeeText('Service')
        ->assertSeeText('Small-business work, finally in one place')
        ->assertSeeText('Built for the messy middle of small business.')
        ->assertSeeText('Electrical & plumbing')
        ->assertSeeText('Tell us what keeps getting lost.')
        ->assertSee('data-public-tabs', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('data-public-tab-trigger="product"', false)
        ->assertSee('data-public-tab-trigger="contact"', false)
        ->assertSee('data-public-mobile-nav', false)
        ->assertSee('data-public-mobile-nav-toggle', false)
        ->assertSee('aria-controls="public-mobile-drawer"', false)
        ->assertSee('data-public-mobile-nav-drawer hidden', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('data-bud-input', false)
        ->assertSee('data-public-bud', false)
        ->assertSee('data-bud-toggle', false)
        ->assertSee('data-bud-panel hidden', false)
        ->assertSeeText('Chat with Bud')
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
        ->assertDontSeeText('Invoice draft in email')
        ->assertSeeText('Become a launch partner with Everbranch')
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

    // Memberless users are guided to create a workspace; either way they never see public home.
    $this->actingAs($user)
        ->get('http://theeverbranch.com/')
        ->assertRedirect(route('workspace.first-login', absolute: false));
});

test('public home route keeps trade examples available', function (): void {
    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Electrical & plumbing')
        ->assertSeeText('Job notes, estimates, parts questions, scheduling notes, and crew next steps.');
});
