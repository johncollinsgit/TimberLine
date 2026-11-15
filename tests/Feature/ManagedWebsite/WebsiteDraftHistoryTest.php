<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteMedia;
use App\Models\TenantSitePublishEvent;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\ManagedWebsite\ManagedWebsiteDraftHistory;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsitePilotService;
use App\Services\Onboarding\SelfServiceTrialService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('local');
    config()->set(['self_service.registration_enabled' => true, 'self_service.trial_enabled' => true,
        'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.screenshot_enabled' => false,
        'managed_website.editor_tenant_ids' => []]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending', 'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'generic']]);
    $this->actingAs($this->owner)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional History Studio', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    $this->owner->refresh();
    $this->actingAs($this->owner);
    $this->tenant = $this->owner->tenants()->sole();
    $this->websites = app(ManagedWebsiteService::class);
    $this->site = $this->websites->createSite($this->tenant, $this->owner);
    $this->page = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->history = app(ManagedWebsiteDraftHistory::class);
    $this->url = fn (string $path): string => 'https://app.theeverbranch.com'.$path.'?tenant='.$this->tenant->slug;
    $this->clock = $this->tenant->accessProfile()->first()->metadata['self_service_trial'];
});

test('only the original audited trial cohort prunes obsolete autosaves and retains private media and audit rows', function (): void {
    expect($this->history->retentionApplies($this->tenant))->toBeTrue();
    $firstPage = $this->page->draft_version_id;
    $firstSite = $this->site->draft_site_version_id;
    Storage::disk('local')->put('kept-photo.png', 'private-photo');
    $media = TenantSiteMedia::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'storage_disk' => 'local', 'storage_path' => 'kept-photo.png', 'file_name' => 'kept-photo.png', 'mime_type' => 'image/png', 'file_size' => 13, 'checksum' => hash('sha256', 'private-photo'), 'kind' => 'image', 'source' => 'upload']);
    for ($i = 0; $i < 36; $i++) {
        $this->websites->saveDraft($this->site, $this->page, ['title' => 'Saved page '.$i, 'blocks' => [['type' => 'text', 'body' => 'Private state '.$i]]], $this->owner);
    }
    for ($i = 0; $i < 14; $i++) {
        $this->websites->saveSiteDraft($this->site, ['settings' => ['theme_name' => 'Saved style '.$i]], $this->owner);
    }
    expect($this->page->versions()->where('status', 'draft')->count())->toBe(30)
        ->and($this->site->siteVersions()->where('status', 'draft')->count())->toBe(10)
        ->and($this->page->versions()->whereKey($firstPage)->exists())->toBeFalse()
        ->and($this->site->siteVersions()->whereKey($firstSite)->exists())->toBeFalse()
        ->and($this->page->fresh()->draftVersion->title)->toBe('Saved page 35')
        ->and(TenantSitePublishEvent::query()->where('tenant_site_id', $this->site->id)->where('event_type', 'page.draft_saved')->count())->toBe(37)
        ->and(TenantSiteMedia::query()->whereKey($media->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->get('kept-photo.png'))->toBe('private-photo')
        ->and($this->tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($this->clock);
    Http::assertNothingSent();
});

test('published versions pointers and publication-event payload references are never pruned', function (): void {
    $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
    $purchases->shouldReceive('paidForTenant')->andReturn(true);
    $purchases->shouldReceive('hasVerifiedPurchase')->andReturn(true);
    app()->instance(SelfServicePurchaseEligibility::class, $purchases);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'self_service_purchase']);
    app(WebsitePilotService::class)->saveSetup($this->tenant, $this->site, ['contact_name' => $this->tenant->name, 'contact_email' => 'fixture@example.test'], $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $publishedPage = $this->page->fresh()->published_version_id;
    $publishedSite = $this->site->fresh()->published_site_version_id;
    $pinnedPage = $this->page->fresh()->draft_version_id;
    $pinnedSite = $this->site->fresh()->draft_site_version_id;
    // Conservatively retain even an old draft ID recorded in a publication audit.
    $this->websites->recordEvent($this->site, $this->page, $this->owner, 'page.rolled_back', ['source_version_id' => $pinnedPage, 'version_id' => $publishedPage]);
    $this->websites->recordEvent($this->site, null, $this->owner, 'site.published', ['source_site_version_id' => $pinnedSite, 'site_version_id' => $publishedSite]);
    for ($i = 0; $i < 35; $i++) {
        $this->websites->saveDraft($this->site, $this->page, ['title' => 'Future page '.$i, 'blocks' => [['type' => 'text', 'body' => 'Future work']]], $this->owner);
        $this->websites->saveSiteDraft($this->site, ['settings' => ['theme_name' => 'Future style '.$i]], $this->owner);
    }
    expect($this->page->versions()->whereKey([$publishedPage, $pinnedPage])->count())->toBe(2)
        ->and($this->site->siteVersions()->whereKey([$publishedSite, $pinnedSite])->count())->toBe(2)
        ->and($this->page->fresh()->published_version_id)->toBe($publishedPage)
        ->and($this->site->fresh()->published_site_version_id)->toBe($publishedSite)
        ->and($this->page->versions()->where('status', 'draft')->count())->toBe(31)
        ->and($this->site->siteVersions()->where('status', 'draft')->count())->toBe(11);
    $this->history->restorePage($this->site->fresh(), $this->page->fresh(), $publishedPage, $this->page->fresh()->draft_version_id, $this->owner);
    $this->history->restoreSite($this->site->fresh(), $publishedSite, $this->site->fresh()->draft_site_version_id, $this->owner);
    expect($this->page->fresh()->published_version_id)->toBe($publishedPage)
        ->and($this->site->fresh()->published_site_version_id)->toBe($publishedSite)
        ->and($this->page->fresh()->draftVersion->status)->toBe('draft')
        ->and($this->site->fresh()->draftSiteVersion->status)->toBe('draft');
});

test('legacy and forged registered histories are never pruned by the new cohort policy', function (bool $registered): void {
    $tenant = Tenant::query()->create(['name' => 'Existing customer history', 'slug' => 'existing-history']);
    if ($registered) {
        TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['self_service_registration' => true, 'self_service_trial' => $this->clock, 'self_service_owner_id' => $this->owner->id]]);
        $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
        $purchases->shouldReceive('paidForTenant')->andReturn(true);
        $purchases->shouldReceive('hasVerifiedPurchase')->andReturn(true);
        app()->instance(SelfServicePurchaseEligibility::class, $purchases);
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'self_service_purchase']);
    }
    $site = $this->websites->createSite($tenant, null);
    $page = $site->pages()->firstOrFail();
    for ($i = 0; $i < 35; $i++) {
        $this->websites->saveDraft($site, $page, ['title' => 'Existing page '.$i, 'blocks' => [['type' => 'text', 'body' => 'Retained history']]], null);
        $this->websites->saveSiteDraft($site, ['settings' => ['theme_name' => 'Existing style '.$i]], null);
    }
    expect($this->history->retentionApplies($tenant))->toBeFalse()
        ->and($page->versions()->count())->toBe(36)->and($site->siteVersions()->count())->toBe(36);
})->with(['legacy' => false, 'metadata without original audit' => true]);

test('owned history restores a new private draft with CAS and leaves clock and public pointers unchanged', function (): void {
    $oldPage = $this->page->draft_version_id;
    $oldSite = $this->site->draft_site_version_id;
    $this->websites->saveDraft($this->site, $this->page, ['title' => 'Later private draft', 'blocks' => [['type' => 'text', 'body' => 'Later private text']]], $this->owner);
    $this->websites->saveSiteDraft($this->site, ['settings' => ['theme_name' => 'Later private style']], $this->owner);
    $read = $this->getJson(($this->url)('/website/editor/'.$this->page->id.'/history'))->assertOk()->assertJsonPath('retention_applies', true)->assertJsonPath('can_restore', true);
    expect($read->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $payload = $read->json();
    $pageRow = collect($payload['page']['versions'])->firstWhere('id', $oldPage);
    $siteRow = collect($payload['site']['versions'])->firstWhere('id', $oldSite);
    config()->set('managed_website.publishing_enabled', false);
    $this->postJson($pageRow['restore_url'], ['current_version_id' => $payload['page']['current_version_id']])->assertOk()->assertJsonPath('status', 'draft_restored');
    $this->postJson($siteRow['restore_url'], ['current_version_id' => $payload['site']['current_version_id']])->assertOk()->assertJsonPath('status', 'draft_restored');
    expect($this->page->fresh()->draft_version_id)->not->toBe($oldPage)
        ->and($this->page->fresh()->draftVersion->title)->toBe($this->tenant->name)
        ->and($this->site->fresh()->draftSiteVersion->settings['theme_name'])->toBe($this->tenant->name)
        ->and($this->site->fresh()->published_site_version_id)->toBeNull()
        ->and($this->page->fresh()->published_version_id)->toBeNull()
        ->and($this->tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($this->clock);
    $this->postJson($pageRow['restore_url'], ['current_version_id' => $payload['page']['current_version_id']])->assertConflict();
    $this->postJson($siteRow['restore_url'], ['current_version_id' => $payload['site']['current_version_id']])->assertConflict();
    $this->postJson($pageRow['restore_url'], [])->assertUnprocessable()->assertJsonValidationErrors('current_version_id');
    $this->postJson(($this->url)('/website/publish'))->assertStatus(423);
    Http::assertNothingSent();
});

test('history cannot read or restore another tenant page or snapshot', function (): void {
    $other = Tenant::query()->create(['name' => 'Other private history', 'slug' => 'other-private-history']);
    $site = $this->websites->createSite($other, null);
    $page = $site->pages()->firstOrFail();
    $pageBefore = $this->page->draft_version_id;
    $siteBefore = $this->site->draft_site_version_id;
    $this->getJson(($this->url)('/website/editor/'.$page->id.'/history'))->assertNotFound();
    $this->postJson(($this->url)('/website/editor/'.$this->page->id.'/history/'.$page->draft_version_id.'/restore'), ['current_version_id' => $pageBefore])->assertNotFound();
    $this->postJson(($this->url)('/website/history/site/'.$site->draft_site_version_id.'/restore'), ['current_version_id' => $siteBefore])->assertNotFound();
    expect($this->page->fresh()->draft_version_id)->toBe($pageBefore)->and($this->site->fresh()->draft_site_version_id)->toBe($siteBefore);
});

test('expired owners can read retained history while expiry and editor freezes stop restore mutations', function (bool $expired): void {
    $old = $this->page->draft_version_id;
    $this->websites->saveDraft($this->site, $this->page, ['title' => 'Current draft', 'blocks' => [['type' => 'text', 'body' => 'Retained private work']]], $this->owner);
    $current = $this->page->fresh()->draft_version_id;
    if ($expired) {
        $this->travelTo(\Carbon\Carbon::parse(app(SelfServiceTrialService::class)->status($this->tenant)['ends_at']));
        $this->getJson(($this->url)('/website/editor/'.$this->page->id.'/history'))->assertOk()->assertJsonPath('can_restore', false)->assertJsonPath('page.current_version_id', $current);
        $this->get(($this->url)('/website'))->assertOk()->assertSee('data-website-version-history', false);
    } else {
        config()->set('managed_website.editor_enabled', false);
    }
    $this->postJson(($this->url)('/website/editor/'.$this->page->id.'/history/'.$old.'/restore'), ['current_version_id' => $current])->assertStatus(423);
    expect($this->page->fresh()->draft_version_id)->toBe($current)
        ->and($this->tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($this->clock);
})->with(['expired' => true, 'editor freeze' => false]);

test('legacy restores keep CAS source reads inside an additional transaction and reject stale replays', function (string $kind): void {
    $tenant = Tenant::query()->create(['name' => 'Legacy recovery', 'slug' => 'legacy-recovery']);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    $site = $this->websites->createSite($tenant, $actor);
    $page = $site->pages()->firstOrFail();
    $source = $kind === 'page' ? $page->draft_version_id : $site->draft_site_version_id;
    $this->websites->saveDraft($site, $page, ['title' => 'Later', 'blocks' => [['type' => 'text', 'body' => 'Later']]], $actor);
    $this->websites->saveSiteDraft($site, ['settings' => ['theme_name' => 'Later']], $actor);
    $current = $kind === 'page' ? $page->fresh()->draft_version_id : $site->fresh()->draft_site_version_id;
    $baseline = DB::transactionLevel();
    $levels = [];
    $tracking = true;
    $table = $kind === 'page' ? 'tenant_site_page_versions' : 'tenant_site_versions';
    DB::listen(function (QueryExecuted $query) use (&$levels, &$tracking, $table): void {
        if ($tracking && str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, $table)) {
            $levels[] = DB::transactionLevel();
        }
    });
    $restore = fn () => $kind === 'page'
        ? $this->history->restorePage($site, $page, $source, $current, $actor)
        : $this->history->restoreSite($site, $source, $current, $actor);
    $restore();
    $tracking = false;
    expect($levels)->not->toBeEmpty()->and(min($levels))->toBeGreaterThan($baseline);
    expect($restore)->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
})->with(['page', 'site']);

test('global admins with viewer inactive or unrelated tenant membership cannot read or restore history', function (bool $paid, string $membership): void {
    $tenant = Tenant::query()->create(['name' => 'Protected history', 'slug' => 'protected-history']);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    if ($membership !== 'other tenant') {
        $actor->tenants()->attach($tenant, ['role' => $membership === 'viewer' ? 'viewer' : 'owner', 'membership_active' => $membership !== 'inactive']);
    } else {
        $actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    }
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'self_service_purchase']);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $site = $this->websites->createSite($tenant, null);
    $page = $site->pages()->firstOrFail();
    if ($paid) {
        TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['self_service_registration' => true]]);
        $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
        $purchases->shouldReceive('paidForTenant')->andReturn(true);
        $purchases->shouldReceive('hasVerifiedPurchase')->andReturn(true);
        app()->instance(SelfServicePurchaseEligibility::class, $purchases);
    }
    $before = $site->getAttributes();
    $this->actingAs($actor)->withSession(['tenant_id' => $tenant->id]);
    $this->getJson(route('managed-website.history.index', ['page' => $page, 'tenant' => $tenant->slug]))->assertForbidden();
    $this->postJson(route('managed-website.history.page.restore', ['page' => $page, 'version' => $page->draft_version_id, 'tenant' => $tenant->slug]), ['current_version_id' => $page->draft_version_id])->assertForbidden();
    $this->postJson(route('managed-website.history.site.restore', ['version' => $site->draft_site_version_id, 'tenant' => $tenant->slug]), ['current_version_id' => $site->draft_site_version_id])->assertForbidden();
    expect(fn () => $this->history->restorePage($site, $page, $page->draft_version_id, $page->draft_version_id, $actor))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($site->fresh()->getAttributes())->toBe($before);
})->with([
    'legacy viewer' => [false, 'viewer'], 'legacy inactive' => [false, 'inactive'], 'legacy unrelated' => [false, 'other tenant'],
    'paid viewer' => [true, 'viewer'], 'paid inactive' => [true, 'inactive'], 'paid unrelated' => [true, 'other tenant'],
]);

test('normal autosaves cannot overwrite a restored draft from another browser', function (bool $legacy): void {
    $site = $this->site;
    $page = $this->page;
    $actor = $this->owner;
    $tenant = $this->tenant;
    if ($legacy) {
        $tenant = Tenant::query()->create(['name' => 'Legacy browser recovery', 'slug' => 'legacy-browser-recovery']);
        $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
        $actor->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial']);
        config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
        $site = $this->websites->createSite($tenant, $actor);
        $page = $site->pages()->firstOrFail();
    }
    $this->actingAs($actor)->withSession(['tenant_id' => $tenant->id]);
    $oldPage = $page->draft_version_id;
    $oldSite = $site->draft_site_version_id;
    $pagePayload = ['current_version_id' => $oldPage, 'title' => 'Newer browser', 'blocks' => [['type' => 'text', 'body' => 'Newer browser text']]];
    $sitePayload = ['current_version_id' => $oldSite, 'settings' => ['theme_name' => 'Newer browser style'], 'navigation' => [['label' => 'Home', 'url' => '/', 'type' => 'page']]];
    $pageUrl = route('managed-website.editor.save', ['page' => $page, 'tenant' => $tenant->slug]);
    $siteUrl = route('managed-website.editor.theme.save', ['tenant' => $tenant->slug]);
    $pageReceipt = $this->putJson($pageUrl, $pagePayload)->assertOk()->json();
    $siteReceipt = $this->putJson($siteUrl, $sitePayload)->assertOk()->json();
    $this->history->restorePage($site->fresh(), $page->fresh(), $oldPage, $pageReceipt['id'], $actor);
    $this->history->restoreSite($site->fresh(), $oldSite, $siteReceipt['id'], $actor);
    $restoredPage = $page->fresh()->draft_version_id;
    $restoredSite = $site->fresh()->draft_site_version_id;
    $this->putJson($pageUrl, [...$pagePayload, 'current_version_id' => $pageReceipt['id']])->assertConflict();
    $this->putJson($siteUrl, [...$sitePayload, 'current_version_id' => $siteReceipt['id']])->assertConflict();
    expect($page->fresh()->draft_version_id)->toBe($restoredPage)->and($site->fresh()->draft_site_version_id)->toBe($restoredSite);
})->with(['trial' => false, 'legacy' => true]);

test('restoring an earlier blank-contact style cannot publish using a newer mutable setup record', function (): void {
    $blank = $this->site->draft_site_version_id;
    app(WebsitePilotService::class)->saveSetup($this->tenant, $this->site, ['contact_name' => $this->tenant->name, 'contact_email' => 'fixture@example.test'], $this->owner);
    $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
    $purchases->shouldReceive('paidForTenant')->andReturn(true);
    $purchases->shouldReceive('hasVerifiedPurchase')->andReturn(true);
    app()->instance(SelfServicePurchaseEligibility::class, $purchases);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'self_service_purchase']);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $published = $this->site->fresh()->published_site_version_id;
    $this->history->restoreSite($this->site->fresh(), $blank, $this->site->fresh()->draft_site_version_id, $this->owner);
    expect($this->site->fresh()->setup->contact_email)->toBe('fixture@example.test')
        ->and(app(WebsitePilotService::class)->readyToPublish($this->site->fresh(), $this->site->fresh()->setup))->toBeFalse();
    $this->postJson(($this->url)('/website/publish'))->assertUnprocessable();
    expect($this->site->fresh()->published_site_version_id)->toBe($published)
        ->and($this->tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($this->clock);
});
