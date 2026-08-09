<?php

use App\Models\User;

test('guest home route renders the marketing landing page by default', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body eb-studio-body"', false)
        ->assertSeeText('Your business has a rhythm.')
        ->assertSeeText('Everbranch helps you keep it.')
        ->assertSeeText('How it works')
        ->assertSeeText('Who it helps')
        ->assertSeeText('Customer Loop')
        ->assertSeeText('Good work should make the next relationship easier.')
        ->assertSeeText('Plan your Customer Loop')
        ->assertSeeText('Contact')
        ->assertSee('data-studio-story', false)
        ->assertSee('data-studio-film', false)
        ->assertDontSee('data-industry-demo', false)
        ->assertSee('data-industry-option="retail"', false)
        ->assertSee('data-industry-option="field"', false)
        ->assertSee('data-industry-option="projects"', false)
        ->assertSee('data-industry-option="studio"', false)
        ->assertSee('data-industry-option="practice"', false)
        ->assertSee('data-industry-option="community"', false)
        ->assertSee('everbranch-hvac-electrical-hero.jpg', false)
        ->assertSee('everbranch-hvac-electrical-field.jpg', false)
        ->assertSee('everbranch-field-owner-office.jpg', false)
        ->assertSee('data-studio-hero-slide', false)
        ->assertSee('everbranch-field-owner-office.jpg', false)
        ->assertSee('data-studio-hero-slide', false)
        ->assertSeeText('Become a launch partner')
        ->assertSeeText('See the Everbranch story')
        ->assertSee('everbranch-story.mp4', false)
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

test('the private surprise story is available only from its direct noindex link', function (): void {
    $this->get('http://theeverbranch.com/story/field-notes-7c8b')
        ->assertOk()
        ->assertSee('noindex, nofollow, noarchive', false)
        ->assertSee('everbranch-story-rickroll-intro.mp4', false)
        ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', false)
        ->assertSeeText('The video switches after four seconds.');

    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertDontSee('field-notes-7c8b', false);
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
        ->assertSeeText('Example business type')
        ->assertSeeText('Operations workspace')
        ->assertSeeText('Field & service teams')
        ->assertSeeText('not a live customer website or workspace')
        ->assertSeeText('Request launch-partner access')
        ->assertSee(route('platform.promo').'#industries', false)
        ->assertDontSee('shopify.app', false)
        ->assertDontSee('tenant.access', false);
});
