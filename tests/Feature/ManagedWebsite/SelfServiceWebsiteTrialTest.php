<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Notifications\SelfServiceVerifyEmail;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use App\Services\ManagedWebsite\ManagedWebsiteSelfServicePolicy;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\Onboarding\SelfServiceTrialService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    config()->set([
        'self_service.registration_enabled' => true, 'self_service.trial_enabled' => true,
        'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.screenshot_enabled' => false,
        'managed_website.custom_domains_enabled' => true, 'managed_website.custom_domain_activation_enabled' => true,
        'managed_website.editor_tenant_ids' => [], 'managed_website.custom_domain_tenant_ids' => [],
    ]);
    $this->post(route('platform.build.save'), ['template' => 'custom', 'continue' => 1])->assertRedirect();
    $this->post(route('self-service.signup.store'), [
        'name' => 'Fictional private Website owner', 'email' => 'private-website-owner@example.test',
        'password' => 'A-long-fictional-password-123!', 'password_confirmation' => 'A-long-fictional-password-123!', 'terms' => 1,
    ])->assertRedirect();
    $this->owner = User::query()->where('email', 'private-website-owner@example.test')->firstOrFail();
    $url = null;
    Notification::assertSentTo($this->owner, SelfServiceVerifyEmail::class, function ($notification) use (&$url): bool {
        $url = $notification->toMail($this->owner)->actionUrl;

        return true;
    });
    $this->get($url)->assertRedirect();
    $this->actingAs($this->owner->refresh());
    $this->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Fictional Private Website', 'template_key' => 'generic', 'start_path' => 'self',
    ])->assertRedirect();
    $this->tenant = $this->owner->tenants()->firstOrFail();
    $this->actingAs($this->owner->refresh());
    expect(app(SelfServiceTrialService::class)->status($this->tenant)['state'])->toBe('active');
    $this->websites = app(ManagedWebsiteService::class);
    $this->site = $this->websites->createSite($this->tenant, $this->owner);
    $this->home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->url = fn (string $path): string => 'https://app.theeverbranch.com'.$path.'?tenant='.$this->tenant->slug;
});

test('a real no-card trial edits and privately previews without an operator grant or public access', function (): void {
    expect(TenantModuleEntitlement::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($this->tenant))->toBeFalse();
    $trial = app(SelfServiceTrialService::class)->status($this->tenant);
    $this->get(($this->url)('/website'))->assertOk()->assertViewHas('isEditorEnabled', true)->assertViewHas('canPublishSite', false)
        ->assertSeeText('Your private trial')->assertSeeText('No card. No automatic charge.')
        ->assertSee('datetime="'.$trial['ends_at'].'"', false)
        ->assertDontSee('action="'.route('managed-website.publish').'"', false)
        ->assertDontSee('action="'.route('managed-website.domains.request').'"', false)
        ->assertDontSee('href="'.route('managed-website.products.index').'"', false);
    $this->get(($this->url)('/website/editor/'.$this->home->id))->assertOk()
        ->assertSee('data-publishing-enabled="false"', false)->assertSee('data-private-trial="true"', false);
    $this->putJson(($this->url)('/website/editor/'.$this->home->id), [
        'title' => 'Private trial heading', 'blocks' => [['type' => 'hero', 'heading' => 'Private trial heading']], 'seo' => [],
    ])->assertOk()->assertJsonStructure(['saved_at', 'version_number']);
    $this->get(($this->url)('/website/editor/'.$this->home->id.'/preview-site'))->assertOk()->assertSeeText('Private trial heading');
    auth()->logout();
    $this->get('https://'.$this->tenant->slug.'.theeverbranch.com/')->assertDontSeeText('Private trial heading')->assertDontSee('data-testid="managed-site"', false);
    expect($this->websites->publicPage($this->tenant, ''))->toBeNull();
    expect($this->site->fresh()->public_enabled)->toBeFalse()
        ->and($this->home->fresh()->draftVersion->title)->toBe('Private trial heading');
    Http::assertNothingSent();
});

test('trial page and theme entrypoints stop before exceeding six actual page rows', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson(($this->url)('/website/pages'), ['title' => 'Private page '.$i, 'slug' => 'private-'.$i, 'page_type' => 'landing'])->assertRedirect();
    }
    $versions = $this->home->versions()->count();
    $this->postJson(($this->url)('/website/pages'), ['title' => 'One too many', 'slug' => 'seventh', 'page_type' => 'landing'])
        ->assertUnprocessable()->assertJsonValidationErrors('title');
    $this->postJson(($this->url)('/website/themes'), ['theme_key' => 'collins-electric'])
        ->assertUnprocessable()->assertJsonValidationErrors('title');
    expect($this->site->pages()->count())->toBe(6)
        ->and($this->site->pages()->where('slug', 'seventh')->exists())->toBeFalse()
        ->and($this->home->versions()->count())->toBe($versions)
        ->and(TenantSite::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('the eleventh trial image is rejected before a file or media row is created', function (): void {
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson(($this->url)('/website/media'), ['image' => UploadedFile::fake()->image('photo-'.$i.'.png', 16, 16)])->assertCreated();
    }
    $this->postJson(($this->url)('/website/media'), ['image' => UploadedFile::fake()->image('eleventh.png', 16, 16)])
        ->assertUnprocessable()->assertJsonValidationErrors('image');
    expect($this->site->media()->count())->toBe(10)->and(Storage::disk('local')->allFiles())->toHaveCount(10);
});

test('recorded trial upload bytes are checked before writing another file', function (): void {
    TenantSiteMedia::query()->create([
        'tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'storage_disk' => 'local',
        'storage_path' => 'existing-photo.png', 'file_name' => 'existing-photo.png', 'mime_type' => 'image/png',
        'file_size' => 50 * 1024 * 1024, 'checksum' => str_repeat('a', 64), 'kind' => 'image', 'source' => 'upload',
    ]);
    $this->postJson(($this->url)('/website/media'), ['image' => UploadedFile::fake()->image('over-storage.png', 16, 16)])
        ->assertUnprocessable()->assertJsonValidationErrors('image');
    expect($this->site->media()->count())->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('private trial photos cannot use a remotely configured local disk label', function (): void {
    config()->set('filesystems.disks.local.driver', 's3');
    $this->postJson(($this->url)('/website/media'), ['image' => UploadedFile::fake()->image('must-stay-local.png', 16, 16)])->assertStatus(503);
    expect($this->site->media()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    Http::assertNothingSent();
});

test('active and expired trial photos remain private to the actual workspace owner', function (bool $expired): void {
    $uploaded = $this->postJson(($this->url)('/website/media'), [
        'image' => UploadedFile::fake()->image('private-owner-photo.png', 16, 16),
    ])->assertCreated();
    $media = TenantSiteMedia::query()->findOrFail($uploaded->json('media.id'));
    $bytes = Storage::disk('local')->get($media->storage_path);
    $path = '/website-media/'.$media->id;
    $otherTenant = Tenant::query()->create(['name' => 'Unrelated private workspace', 'slug' => 'unrelated-private-workspace']);
    $otherOwner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $otherOwner->tenants()->attach($otherTenant->id, ['role' => 'owner', 'membership_active' => true]);
    if ($expired) {
        $this->travel(15)->days();
    }

    expect(app(SelfServiceTrialService::class)->status($this->tenant)['state'])->toBe($expired ? 'expired' : 'active')
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($this->tenant))->toBeFalse()
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    $photo = $this->get('https://app.theeverbranch.com'.$path)->assertOk()
        ->assertHeader('Content-Type', 'image/png')->assertStreamedContent($bytes);
    expect($photo->headers->get('Cache-Control'))->toContain('private', 'no-store');

    $this->actingAs($otherOwner)->withSession(['active_tenant_id' => $otherTenant->id]);
    $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    $this->get('https://app.theeverbranch.com'.$path.'?tenant='.$this->tenant->slug)->assertNotFound();
    auth()->forgetGuards();
    $this->flushSession();
    $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    $this->get('https://'.$this->tenant->slug.'.theeverbranch.com'.$path)->assertNotFound();

    $this->actingAs($this->owner);
    $retained = $this->get('https://app.theeverbranch.com'.$path)->assertOk()->assertStreamedContent($bytes);
    expect($retained->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($this->site->fresh()->public_enabled)->toBeFalse();
    Http::assertNothingSent();
})->with(['active' => false, 'expired' => true]);

test('expired trials retain owned preview and photos but cannot write or reopen through a pilot allowlist', function (): void {
    $uploaded = $this->postJson(($this->url)('/website/media'), ['image' => UploadedFile::fake()->image('retained.png', 16, 16)])->assertCreated();
    $media = TenantSiteMedia::query()->findOrFail($uploaded->json('media.id'));
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id]);
    config()->set('managed_website.custom_domain_tenant_ids', [$this->tenant->id]);
    $this->travel(15)->days();
    $versions = $this->home->versions()->count();
    expect($this->websites->editorEnabledFor($this->tenant))->toBeFalse()
        ->and($this->websites->draftViewingEnabledFor($this->tenant))->toBeTrue()
        ->and(app(ManagedWebsiteDomainService::class)->enabledFor($this->tenant))->toBeFalse();
    $this->get(($this->url)('/website'))->assertOk()->assertViewHas('isEditorEnabled', false)->assertViewHas('canViewDrafts', true)
        ->assertSeeText('Your trial ended')->assertSeeText('Preview saved draft')
        ->assertDontSee('action="'.route('managed-website.setup.save').'"', false)
        ->assertDontSee('action="'.route('managed-website.themes.apply').'"', false)
        ->assertDontSee('action="'.route('managed-website.pages.store').'"', false)
        ->assertDontSee('href="'.route('managed-website.editor', ['page' => $this->home]).'"', false);
    $this->get(($this->url)('/website/editor/'.$this->home->id.'/preview-site'))->assertOk()
        ->assertViewHas('previewBackLabel', 'Back to Website')
        ->assertViewHas('editorUrl', route('managed-website.index', ['tenant' => $this->tenant->slug]));
    $photo = $this->get('https://app.theeverbranch.com/website-media/'.$media->id)->assertOk();
    expect($photo->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->get(($this->url)('/website/editor/'.$this->home->id))->assertStatus(423);
    $this->putJson(($this->url)('/website/editor/'.$this->home->id), ['title' => 'Blocked', 'blocks' => [['type' => 'hero', 'heading' => 'Blocked']]])->assertStatus(423);
    $this->postJson(($this->url)('/website/media'), ['image' => UploadedFile::fake()->image('expired.png', 16, 16)])->assertStatus(423);
    $this->postJson(($this->url)('/website/editor/publish'))->assertStatus(423);
    expect($this->home->versions()->count())->toBe($versions)->and($this->site->media()->count())->toBe(1)
        ->and($this->site->fresh()->published_site_version_id)->toBeNull();
    Http::assertNothingSent();
});

test('trial publishing rollback domains and offering side entries require genuine paid access', function (): void {
    $domains = app(ManagedWebsiteDomainService::class);
    expect(fn () => $this->websites->publish($this->site, $this->owner))->toThrow(HttpException::class);
    expect(fn () => $this->websites->rollback($this->site, $this->home, $this->home->draftVersion, $this->owner))->toThrow(HttpException::class);
    expect(fn () => $domains->request($this->site, 'www.private-website.example', $this->owner))->toThrow(HttpException::class);
    $pending = TenantSiteDomain::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'hostname' => 'www.private-website.example', 'status' => 'pending', 'verification_token' => 'fictional-not-a-proof']);
    expect(fn () => $domains->verify($pending, $this->owner))->toThrow(HttpException::class);
    $this->postJson(($this->url)('/website/domains'), ['domain' => 'www.private-website.example'])->assertStatus(423);
    $this->postJson(($this->url)('/website/setup'), ['theme_key' => 'collins-electric', 'service_title' => 'Forbidden catalog entry'])
        ->assertStatus(423);
    expect(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($this->site->fresh()->public_enabled)->toBeFalse()
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($this->tenant))->toBeFalse();
    Http::assertNothingSent();
});

test('explicit Website module denial and the global editor freeze override an active private trial', function (): void {
    config()->set('managed_website.editor_enabled', false);
    expect($this->websites->editorEnabledFor($this->tenant))->toBeFalse()
        ->and($this->websites->draftViewingEnabledFor($this->tenant))->toBeFalse();
    config()->set('managed_website.editor_enabled', true);
    TenantModuleState::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website'], ['enabled_override' => false]);
    expect($this->websites->editorEnabledFor($this->tenant))->toBeFalse()
        ->and($this->websites->draftViewingEnabledFor($this->tenant))->toBeFalse();
});

test('future or unavailable Website catalog status cannot be bypassed by a real active private clock', function (string $status): void {
    config()->set('module_catalog.modules.managed_website.status', $status);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    app()->forgetInstance(ManagedWebsiteSelfServicePolicy::class);
    expect(app(SelfServiceTrialService::class)->status($this->tenant)['state'])->toBe('active')
        ->and($this->websites->editorEnabledFor($this->tenant))->toBeFalse()
        ->and($this->websites->draftViewingEnabledFor($this->tenant))->toBeFalse();
    $this->putJson(($this->url)('/website/editor/'.$this->home->id), ['title' => 'Blocked future module', 'blocks' => [['type' => 'hero', 'heading' => 'Blocked']]])->assertForbidden();
    expect($this->home->fresh()->draftVersion->title)->not->toBe('Blocked future module');
})->with(['placeholder', 'disabled']);

test('coming soon and hard dependencies still restrict a real private trial', function (string $restriction): void {
    if ($restriction === 'coming soon') {
        TenantModuleState::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website'], ['coming_soon_override' => true]);
    } else {
        config()->set('module_catalog.modules.managed_website.dependencies', ['shopify']);
    }
    app()->forgetInstance(TenantModuleAccessResolver::class);
    app()->forgetInstance(ManagedWebsiteSelfServicePolicy::class);
    expect(app(SelfServiceTrialService::class)->status($this->tenant)['state'])->toBe('active')
        ->and($this->websites->editorEnabledFor($this->tenant))->toBeFalse()
        ->and($this->websites->draftViewingEnabledFor($this->tenant))->toBeFalse();
    $this->get(($this->url)('/website/editor/'.$this->home->id.'/preview-site'))->assertForbidden();
})->with(['coming soon', 'dependency']);
