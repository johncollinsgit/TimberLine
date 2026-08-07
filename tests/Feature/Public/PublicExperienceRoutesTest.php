<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body eb-studio-body"', false)
        ->assertSeeText('Your business has a rhythm.')
        ->assertSeeText('Everbranch helps you keep it.')
        ->assertSeeText('One flat price for the business. No per-user fees.')
        ->assertSeeText('How it works')
        ->assertSeeText('Who it helps')
        ->assertSeeText('Contact')
        ->assertSee('data-studio-story', false)
        ->assertSee('data-studio-film', false)
        ->assertSee('data-industry-demo', false)
        ->assertSee('data-industry-option="retail"', false)
        ->assertSee('data-industry-option="field"', false)
        ->assertSee('data-industry-option="projects"', false)
        ->assertSee('data-industry-option="studio"', false)
        ->assertSee('data-industry-option="practice"', false)
        ->assertSee('data-industry-option="community"', false)
        ->assertSee('data-industry-view="website"', false)
        ->assertSee('data-industry-view="workspace"', false)
        ->assertSee('everbranch-hvac-electrical-hero.jpg', false)
        ->assertSee('everbranch-hvac-electrical-field.jpg', false)
        ->assertSee('everbranch-field-owner-office.jpg', false)
        ->assertSee('data-studio-hero-slide', false)
        ->assertSee('everbranch-field-owner-office.jpg', false)
        ->assertSee('data-studio-hero-slide', false)
        ->assertSeeText('Become a launch partner')
        ->assertSeeText('Log in')
        ->assertSee('brand/everbranch-lockup.svg?v='.$cacheTag, false)
        ->assertSee('brand/everbranch-mark.svg?v='.$cacheTag, false)
        ->assertSee(route('platform.plans'), false)
        ->assertSee(route('platform.modules.explore'), false)
        ->assertSee(route('platform.start'), false)
        ->assertDontSee('data-public-tabs', false)
        ->assertDontSee('data-public-tab-trigger', false)
        ->assertDontSeeText('Forestry Backstage')
        ->assertDontSeeText('Backstage')
        ->assertDontSeeText('Welcome back');
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

test('public home route keeps field-service examples available', function (): void {
    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Field & service teams')
        ->assertSeeText('Give office and field teams one living record for every job.');
});

test('public industry example pages remain isolated from tenant and Shopify surfaces', function (): void {
    $this->get(route('platform.industry-demo', ['discipline' => 'field']))
        ->assertOk()
        ->assertSee('data-industry-page', false)
        ->assertSee('data-industry-key="field"', false)
        ->assertSeeText('Back to Everbranch')
        ->assertSeeText('Business type')
        ->assertSeeText('Operations workspace')
        ->assertSeeText('Field & service teams')
        ->assertSee(route('platform.promo').'#industries', false)
        ->assertDontSee('shopify.app', false)
        ->assertDontSee('tenant.access', false);
});
