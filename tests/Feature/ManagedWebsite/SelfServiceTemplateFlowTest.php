<?php

use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsitePilotService;
use App\Services\ManagedWebsite\WebsiteStarterCatalog;
use App\Services\ManagedWebsite\WebsiteThemeCatalog;
use App\Services\Onboarding\SelfServiceTemplateSelectionService;
use App\Services\Onboarding\SelfServiceTrialService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();
    Storage::fake('local');
    config()->set([
        'self_service.registration_enabled' => true, 'self_service.trial_enabled' => true,
        'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.screenshot_enabled' => false,
        'managed_website.editor_tenant_ids' => [],
    ]);
});

/** Actual account/verification/login/workspace requests; no payment or provider fake. */
function templateFlowOwner(Tests\TestCase $test, string $preset): array
{
    $email = 'template-'.$preset.'@example.test';
    $password = 'A-long-fictional-template-password-123!';
    $test->post(route('platform.build.save'), ['template' => $preset, 'continue' => 1])->assertRedirect(route('self-service.signup'));
    $test->post(route('self-service.signup.store'), [
        'name' => 'Template Owner', 'email' => $email, 'password' => $password, 'password_confirmation' => $password, 'terms' => 1,
    ])->assertRedirect(route('self-service.verify.notice'));
    $owner = User::query()->where('email', $email)->firstOrFail();
    $verification = null;
    Notification::assertSentTo($owner, SelfServiceVerifyEmail::class, function ($notice) use ($owner, &$verification): bool {
        $verification = $notice->toMail($owner)->actionUrl;

        return true;
    });
    Auth::logout();
    $test->flushSession();
    $test->get($verification)->assertOk()->assertSeeText('Email confirmed');
    $test->assertGuest();
    $test->post(route('login'), ['email' => $email, 'password' => $password])->assertRedirect(route('workspace.first-login', absolute: false));
    $test->get(route('workspace.first-login'))->assertOk();
    $test->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional '.$preset, 'template_key' => 'law', 'start_path' => 'self'])->assertRedirectContains('/workspace/guide');
    Auth::forgetGuards();
    $owner->refresh();
    $tenant = $owner->tenants()->sole();

    return [$owner, $tenant, fn (string $path): string => 'https://app.theeverbranch.com'.$path.'?tenant='.$tenant->slug];
}

test('each business template completes a neutral illustrated six-page private website after fresh email login', function (string $preset): void {
    [$owner, $tenant, $url] = templateFlowOwner($this, $preset);
    $catalog = app(WebsiteStarterCatalog::class);
    $key = $catalog->recommendedKey($tenant);
    expect($key)->toBe('starter-'.$preset)
        ->and($owner->onboarding_guide_answers['business_template'])->toBe($preset)
        ->and($tenant->setupStatus->module_interests)->toBe(app(SelfServiceTemplateSelectionService::class)->branches($preset));
    $this->get($url('/website'))->assertOk()->assertViewHas('recommendedThemeKey', $key)
        ->assertSee('value="'.$key.'" class="mt-1" checked', false)
        ->assertDontSeeText('Sawyer Naturals')->assertDontSeeText('Collins Upstate Electric');
    $clock = $tenant->accessProfile->metadata['self_service_trial'];
    $name = 'Fictional '.$preset.' studio';
    $this->post($url('/website/setup'), ['theme_key' => $key, 'contact_name' => $name, 'contact_email' => 'website-fixture@gmail.com', 'contact_phone' => '+1 (555) 010-1234', 'hours' => 'By appointment', 'service_area' => 'Fictional demonstration'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $site = TenantSite::query()->forTenant($tenant)->with(['pages.draftVersion', 'draftSiteVersion', 'setup'])->sole();
    expect($site->pages)->toHaveCount(6)
        ->and($site->pages->pluck('slug')->unique())->toHaveCount(6)
        ->and($site->draftSiteVersion->settings['theme_name'])->toBe($name)
        ->and($site->draftSiteVersion->settings['business_contact']['email'])->toBe('website-fixture@gmail.com')
        ->and($site->setup->business_mode)->toBe(config('website_starters.'.$preset.'.mode'))
        ->and($site->public_enabled)->toBeFalse()
        ->and($tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($clock);
    $paths = $site->pages->map(fn ($page): string => $page->slug === '/' ? '/' : '/'.$page->slug)->all();
    foreach ($site->draftSiteVersion->navigation as $item) {
        expect($item['url'])->toBeIn($paths);
    }
    foreach ($site->pages as $page) {
        $hero = $page->draftVersion->blocks[0];
        expect($hero['type'])->toBe('hero')->and($hero['image_alt'])->not->toBe('');
        $this->assertFileExists(public_path(ltrim($hero['image_url'], '/')));
        $preview = $this->get($url('/website/editor/'.$page->id.'/preview-site'))->assertOk()
            ->assertSeeText($name)->assertSeeText('website-fixture@gmail.com')
            ->assertDontSeeText('Sawyer')->assertDontSeeText('Collins')->assertDontSeeText('864-640-6642')
            ->assertDontSeeText('Free shipping')->assertDontSee('mailto:sawyernaturals', false);
        expect($preview->headers->get('Cache-Control'))->toContain('no-store');
        if ($page->slug === 'contact') {
            $preview->assertSeeText('By appointment')->assertSeeText('Fictional demonstration');
        }
    }
    expect(Tenant::query()->count())->toBe(1)
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantBillingReceipt::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantBillingSubscription::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantSiteDomain::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['generic', 'candle_maker', 'landscaping', 'electrician', 'law', 'apparel', 'custom']);

test('paid business detail recovery preserves the original trial HVAC content photos navigation and published pointers', function (): void {
    [$owner, $tenant, $url] = templateFlowOwner($this, 'candle_maker');
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $owner);
    $websites->applyTheme($site, 'hvac-service', $owner);
    app(WebsitePilotService::class)->saveSetup($tenant, $site, ['theme_key' => 'hvac-service', 'contact_name' => $tenant->name], $owner);
    app(WebsitePilotService::class)->markMobilePreviewed($tenant, $owner);
    $upload = $this->postJson($url('/website/media'), ['image' => UploadedFile::fake()->image('trial-home.png', 24, 24), 'alt_text' => 'Owned fictional home'])->assertCreated();
    $home = $site->pages()->where('slug', '/')->firstOrFail();
    $this->putJson($url('/website/editor/'.$home->id), ['current_version_id' => $home->draft_version_id, 'title' => 'Keep my work', 'blocks' => [['type' => 'hero', 'heading' => 'Keep my exact private heading', 'image_url' => $upload->json('media.url'), 'image_alt' => 'Owned fictional home']], 'seo' => []])->assertOk();
    $clock = $tenant->accessProfile()->first()->metadata['self_service_trial'];
    // Only this disposable fixture simulates the already-proved common payment admission.
    $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
    $purchases->shouldReceive('paidForTenant')->andReturn(true);
    $purchases->shouldReceive('hasVerifiedPurchase')->andReturn(true);
    app()->instance(SelfServicePurchaseEligibility::class, $purchases);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'self_service_purchase']);
    $this->get($url('/website'))->assertOk()->assertSeeText('Add business name and email')->assertSee('name="theme_key" value="hvac-service"', false);
    $this->postJson($url('/website/publish'))->assertUnprocessable();
    $pages = $site->pages()->orderBy('id')->get()->map->getAttributes()->all();
    $blocks = $home->fresh()->draftVersion->blocks;
    $nav = $site->fresh()->draftSiteVersion->navigation;
    $version = $site->fresh()->draft_site_version_id;
    $this->post($url('/website/setup'), ['theme_key' => 'hvac-service', 'site_version' => $version, 'contact_name' => $tenant->name, 'contact_email' => 'website-fixture@gmail.com', 'hours' => 'By appointment'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $site->refresh();
    expect($site->pages()->orderBy('id')->get()->map->getAttributes()->all())->toBe($pages)
        ->and($home->fresh()->draftVersion->blocks)->toBe($blocks)
        ->and($site->draftSiteVersion->navigation)->toBe($nav)
        ->and($site->draftSiteVersion->settings['theme_key'])->toBe('hvac-service')
        ->and($site->published_site_version_id)->toBeNull()
        ->and($site->setup->completed_steps)->toContain('mobile_preview')
        ->and($tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($clock)
        ->and(TenantSiteMedia::query()->count())->toBe(1);
    expect(app(WebsitePilotService::class)->readyToPublish($site, $site->setup))->toBeTrue();
    $websites->publish($site, $owner);
    $published = $site->fresh()->published_site_version_id;
    $publishedPages = $site->pages()->orderBy('id')->pluck('published_version_id')->all();
    $this->post($url('/website/setup'), ['theme_key' => 'hvac-service', 'site_version' => $site->fresh()->draft_site_version_id, 'contact_name' => 'Updated business', 'contact_email' => 'website-fixture@gmail.com'])->assertRedirect()->assertSessionHasNoErrors();
    expect($site->fresh()->published_site_version_id)->toBe($published)
        ->and($site->pages()->orderBy('id')->pluck('published_version_id')->all())->toBe($publishedPages)
        ->and($home->fresh()->draftVersion->blocks)->toBe($blocks);
    $this->postJson($url('/website/setup'), ['theme_key' => 'hvac-service', 'site_version' => $version, 'contact_name' => 'Stale edit'])->assertConflict();
    $this->postJson($url('/website/setup'), ['theme_key' => 'hvac-service', 'contact_name' => 'Missing revision'])->assertUnprocessable()->assertJsonValidationErrors('site_version');
    expect($site->fresh()->draftSiteVersion->settings['theme_name'])->toBe('Updated business');
    Http::assertNothingSent();
});

test('expired and explicitly disabled trials cannot mutate details or replace a design', function (bool $disabled): void {
    [$owner, $tenant, $url] = templateFlowOwner($this, 'custom');
    $this->post($url('/website/setup'), ['theme_key' => 'starter-custom', 'contact_name' => $tenant->name])->assertRedirect();
    $site = TenantSite::query()->forTenant($tenant)->sole();
    $before = $site->getAttributes();
    if ($disabled) {
        TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'enabled_override' => false]);
    } else {
        $this->travelTo(\Carbon\Carbon::parse(app(SelfServiceTrialService::class)->status($tenant)['ends_at']));
    }
    $this->postJson($url('/website/setup'), ['theme_key' => 'starter-custom', 'contact_name' => 'Denied change'])->assertStatus($disabled ? 403 : 423);
    $this->postJson($url('/website/themes'), ['theme_key' => 'starter-generic', 'replace_confirmed' => 1])->assertStatus($disabled ? 403 : 423);
    expect($site->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
})->with(['expired' => false, 'operator disabled' => true]);

test('design replacement needs explicit confirmation and original branded examples remain intact', function (): void {
    [$owner, $tenant, $url] = templateFlowOwner($this, 'generic');
    $this->post($url('/website/setup'), ['theme_key' => 'starter-generic', 'contact_name' => $tenant->name])->assertRedirect();
    $site = TenantSite::query()->forTenant($tenant)->sole();
    $draft = $site->draft_site_version_id;
    $this->postJson($url('/website/themes'), ['theme_key' => 'starter-electrician'])->assertUnprocessable()->assertJsonValidationErrors('replace_confirmed');
    expect($site->fresh()->draft_site_version_id)->toBe($draft);
    $this->post($url('/website/themes'), ['theme_key' => 'starter-electrician', 'replace_confirmed' => 1])->assertRedirect();
    expect($site->fresh()->settings['theme_key'])->toBe('starter-electrician')->and($site->pages()->count())->toBe(6)->and($site->public_enabled)->toBeFalse();
    $original = app(WebsiteThemeCatalog::class)->find('collins-electric');
    expect($original['settings']['theme_name'])->toBe('Collins Upstate Electric')
        ->and($original['pages'][0]['blocks'][0]['cta_url'])->toBe('tel:+18646406642');
    Http::assertNothingSent();
});
