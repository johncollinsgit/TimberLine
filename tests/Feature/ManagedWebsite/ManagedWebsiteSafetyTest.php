<?php

use App\Http\Controllers\ManagedWebsiteController;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteMedia;
use App\Models\TenantSitePage;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('managed_website.commerce_enabled', true);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
});

function managedWebsiteTenant(string $slug = 'managed-site-pilot'): Tenant
{
    return Tenant::query()->create(['name' => 'Managed Website Pilot', 'slug' => $slug]);
}

function managedWebsiteActor(Tenant $tenant): User
{
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin']);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'managed_website',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_paid',
        'entitlement_source' => 'test',
        'price_source' => 'catalog',
    ]);

    return $actor;
}

/** @param array<string,mixed> $document */
function managedWebsiteGlb(array $document = []): string
{
    $document = array_replace_recursive([
        'asset' => ['version' => '2.0', 'generator' => 'Everbranch test'],
        'extensionsUsed' => ['KHR_texture_transform'],
        'animations' => [['name' => 'raise'], ['name' => 'lower']],
        'nodes' => [['name' => 'cbc_root'], ['name' => 'hotspot_lift']],
    ], $document);
    $json = json_encode($document, JSON_THROW_ON_ERROR);
    $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);

    return pack('V3', 0x46546c67, 2, 20 + strlen($json))
        .pack('V2', strlen($json), 0x4e4f534a)
        .$json;
}

test('interactive product viewer stores only owned media IDs and model metadata in immutable snapshots', function (): void {
    $tenant = managedWebsiteTenant('viewer-pilot');
    $actor = managedWebsiteActor($tenant);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $page = $site->pages()->where('slug', '/')->firstOrFail();
    $model = TenantSiteMedia::query()->create([
        'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'test/barrel.glb',
        'file_name' => 'barrel.glb', 'mime_type' => 'model/gltf-binary', 'file_size' => 100, 'checksum' => str_repeat('a', 64), 'kind' => 'model', 'source' => 'upload',
        'metadata' => ['format' => 'glb', 'animation_clips' => ['raise', 'lower'], 'nodes' => ['cbc_root', 'hotspot_lift']],
    ]);
    $poster = TenantSiteMedia::query()->create([
        'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'test/barrel.webp',
        'file_name' => 'barrel.webp', 'mime_type' => 'image/webp', 'file_size' => 100, 'checksum' => str_repeat('b', 64), 'kind' => 'image', 'source' => 'upload',
    ]);

    $version = $service->saveDraft($site, $page, ['title' => 'Barrel lift', 'blocks' => [[
        'type' => 'interactive_product_viewer', 'model_media_id' => $model->id, 'poster_media_id' => $poster->id,
        'id' => 'lift-viewer', 'label' => 'Explore the barrel', 'heading' => 'Wine cabinet', 'poster_alt' => 'Closed wine barrel', 'raise_label' => 'Raise cabinet', 'lower_label' => 'Lower cabinet',
        'variants' => [
            ['id' => 'whiskey', 'label' => 'Whiskey Bar', 'model_media_id' => $model->id, 'poster_media_id' => $poster->id],
            ['id' => 'display', 'label' => 'Display', 'model_media_id' => $model->id, 'poster_media_id' => $poster->id],
        ],
        'hotspots' => [['id' => 'lift', 'label' => 'Lift', 'body' => 'Visible lift hardware.', 'node_name' => 'hotspot_lift']],
    ]]], $actor);
    $block = $version->blocks[0];
    expect($block['model_media_id'])->toBe($model->id)
        ->and($block['poster_media_id'])->toBe($poster->id)
        ->and($block['raise_clip'])->toBe('raise')
        ->and($block['lower_clip'])->toBe('lower')
        ->and($block['id'])->toBe('lift-viewer')
        ->and($block['label'])->toBe('Explore the barrel')
        ->and($block['poster_alt'])->toBe('Closed wine barrel')
        ->and($block['variants'][1]['id'])->toBe('display')
        ->and($block)->not->toHaveKey('model_url');

    $service->publish($site, $actor);
    expect($page->fresh()->publishedVersion->blocks[0]['model_media_id'])->toBe($model->id)
        ->and($service->interactiveProductViewerMedia($site, $block))->toMatchArray(['model_url' => route('managed-website.media.show', $model), 'poster_url' => route('managed-website.media.show', $poster)]);
});

test('interactive product viewer rejects media from another tenant and unknown animation or hotspot nodes', function (): void {
    $tenant = managedWebsiteTenant('viewer-owner');
    $other = managedWebsiteTenant('viewer-other');
    $actor = managedWebsiteActor($tenant);
    managedWebsiteActor($other);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $otherSite = $service->createSite($other, $actor);
    $page = $site->pages()->where('slug', '/')->firstOrFail();
    $foreignModel = TenantSiteMedia::query()->create([
        'tenant_id' => $other->id, 'tenant_site_id' => $otherSite->id, 'storage_disk' => 'local', 'storage_path' => 'test/foreign.glb',
        'file_name' => 'foreign.glb', 'mime_type' => 'model/gltf-binary', 'file_size' => 100, 'checksum' => str_repeat('c', 64), 'kind' => 'model', 'source' => 'upload',
        'metadata' => ['animation_clips' => ['raise', 'lower'], 'nodes' => ['hotspot']],
    ]);
    $poster = TenantSiteMedia::query()->create([
        'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'test/owner.webp',
        'file_name' => 'owner.webp', 'mime_type' => 'image/webp', 'file_size' => 100, 'checksum' => str_repeat('d', 64), 'kind' => 'image', 'source' => 'upload',
    ]);

    expect(fn () => $service->saveDraft($site, $page, ['title' => 'Nope', 'blocks' => [[
        'type' => 'interactive_product_viewer', 'model_media_id' => $foreignModel->id, 'poster_media_id' => $poster->id,
    ]]], $actor))->toThrow(ValidationException::class)
        ->and($service->interactiveProductViewerMedia($site, ['model_media_id' => $foreignModel->id, 'poster_media_id' => $poster->id]))->toBeNull();

    $model = TenantSiteMedia::query()->create([
        'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'test/owner.glb',
        'file_name' => 'owner.glb', 'mime_type' => 'model/gltf-binary', 'file_size' => 100, 'checksum' => str_repeat('e', 64), 'kind' => 'model', 'source' => 'upload',
        'metadata' => ['animation_clips' => ['raise', 'lower'], 'nodes' => ['cbc_root']],
    ]);
    expect(fn () => $service->saveDraft($site, $page, ['title' => 'Nope', 'blocks' => [[
        'type' => 'interactive_product_viewer', 'model_media_id' => $model->id, 'poster_media_id' => $poster->id, 'lower_clip' => 'missing',
    ]]], $actor))->toThrow(ValidationException::class);
    expect(fn () => $service->saveDraft($site, $page, ['title' => 'Nope', 'blocks' => [[
        'type' => 'interactive_product_viewer', 'model_media_id' => $model->id, 'poster_media_id' => $poster->id,
        'hotspots' => [['id' => 'missing', 'node_name' => 'not_a_model_node']],
    ]]], $actor))->toThrow(ValidationException::class);
});

test('model uploads accept a self-contained GLB and expose inspected editor metadata', function (): void {
    $tenant = managedWebsiteTenant('viewer-upload');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('barrel.glb', managedWebsiteGlb());
    $request = Request::create('/website/media', 'POST', ['alt_text' => 'Closed wine barrel'], [], ['model' => $file]);
    $request->attributes->set('current_tenant', $tenant);
    $request->setUserResolver(fn () => $actor);
    app(ManagedWebsiteService::class)->createSite($tenant, $actor);

    $response = app(ManagedWebsiteController::class)->storeMedia($request, app(ManagedWebsiteService::class));
    $payload = $response->getData(true)['media'];
    expect($response->status())->toBe(201)
        ->and($payload['kind'])->toBe('model')
        ->and($payload['mime_type'])->toBe('model/gltf-binary')
        ->and($payload['metadata']['animation_clips'])->toBe(['raise', 'lower'])
        ->and($payload['metadata']['nodes'])->toContain('hotspot_lift');
});

test('model inspection rejects GLBs with remote or URI resources', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'everbranch-glb-');
    file_put_contents($path, managedWebsiteGlb(['buffers' => [['byteLength' => 10, 'uri' => 'https://example.test/model.bin']]]));

    try {
        expect(fn () => app(ManagedWebsiteService::class)->inspectGlb($path))->toThrow(ValidationException::class);
    } finally {
        @unlink($path);
    }
});

test('product video blocks retain owned media IDs and resolve the video only at render time', function (): void {
    $tenant = managedWebsiteTenant('product-video');
    $actor = managedWebsiteActor($tenant);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $page = $site->pages()->where('slug', '/')->firstOrFail();
    $video = TenantSiteMedia::query()->create([
        'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'test/lift.mp4',
        'file_name' => 'lift.mp4', 'mime_type' => 'video/mp4', 'file_size' => 100, 'checksum' => str_repeat('f', 64), 'kind' => 'video', 'source' => 'upload',
    ]);
    $poster = TenantSiteMedia::query()->create([
        'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'test/lift.webp',
        'file_name' => 'lift.webp', 'mime_type' => 'image/webp', 'file_size' => 100, 'checksum' => str_repeat('1', 64), 'kind' => 'image', 'source' => 'upload',
    ]);

    $version = $service->saveDraft($site, $page, ['title' => 'Lift motion', 'blocks' => [[
        'type' => 'product_video', 'video_media_id' => $video->id, 'poster_media_id' => $poster->id, 'heading' => 'Watch it rise',
    ]]], $actor);

    expect($version->blocks[0]['video_media_id'])->toBe($video->id)
        ->and($version->blocks[0])->not->toHaveKey('video_url')
        ->and($service->productVideoMedia($site, $version->blocks[0])['video_url'])->toBe(route('managed-website.media.show', $video));
});

test('managed website publishing is additive and creates immutable snapshots', function (): void {
    $tenant = managedWebsiteTenant();
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $ordersBefore = Order::query()->count();
    Http::preventStrayRequests();

    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $home = $site->pages()->where('slug', '/')->firstOrFail();
    $service->saveDraft($site, $home, [
        'title' => 'A safer pilot site',
        'blocks' => [['type' => 'hero', 'heading' => 'A safer pilot site', 'body' => 'Customer-owned content only.']],
        'seo' => ['title' => 'Pilot', 'description' => 'A safe test.'],
    ], $actor);
    $service->publish($site, $actor);

    $home->refresh();
    expect($site->fresh()->status)->toBe('published')
        ->and($home->published_version_id)->not->toBeNull()
        ->and($home->versions()->where('status', 'published')->count())->toBe(1)
        ->and(Order::query()->count())->toBe($ordersBefore);
});

test('managed website public host renders only an explicitly published snapshot', function (): void {
    $tenant = managedWebsiteTenant('safe-pilot');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $service->publish($site, $actor);

    $this->get('https://safe-pilot.theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Managed Website Pilot');

    $this->post('https://safe-pilot.theeverbranch.com/a-route-that-does-not-exist')
        ->assertNotFound();
});

test('managed website editor renders its structured page data', function (): void {
    $tenant = managedWebsiteTenant('editor-pilot');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    $page = $site->pages()->where('slug', '/')->firstOrFail()->load('draftVersion');

    $html = view('managed-website.editor', [
        'tenant' => $tenant,
        'site' => $site->load('pages.draftVersion'),
        'page' => $page,
        'pages' => $site->pages,
        'isPublishingEnabled' => true,
    ])->render();

    expect($html)
        ->toContain('id="managed-website-editor-root"')
        ->toContain('data-page=')
        ->toContain('data-pages=')
        ->toContain('data-site=');
});

test('the authenticated draft preview may be framed only by the workspace editor', function (): void {
    $tenant = managedWebsiteTenant('preview-frame-pilot');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    $page = $site->pages()->where('slug', '/')->firstOrFail();
    $request = Request::create('/website/editor/'.$page->id.'/preview');
    $request->attributes->set('current_tenant', $tenant);

    $response = app(ManagedWebsiteController::class)->preview($request, $page, app(ManagedWebsiteService::class));

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

test('the editor canvas marks individual content elements without activating customer links', function (): void {
    $tenant = managedWebsiteTenant('element-selection-pilot');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $page = $site->pages()->where('slug', '/')->firstOrFail();
    $service->saveDraft($site, $page, [
        'title' => 'Individual editing',
        'blocks' => [[
            'type' => 'service_cards',
            'label' => 'Services',
            'heading' => 'Choose a service',
            'body' => 'One clear next step.',
            'items' => [['heading' => 'Panel work', 'body' => 'A safer electrical panel.', 'image_url' => '/images/panel.jpg']],
        ]],
    ], $actor);
    $service->saveSiteDraft($site, [
        'settings' => ['announcement' => ['enabled' => true, 'text' => 'A useful announcement']],
        'navigation' => [['label' => 'Home', 'url' => '/', 'type' => 'page']],
    ], $actor);
    $request = Request::create('/website/editor/'.$page->id.'/preview');
    $request->attributes->set('current_tenant', $tenant);

    $content = app(ManagedWebsiteController::class)->preview($request, $page, $service)->getContent();

    expect($content)
        ->toContain('data-eb-field="announcement_text"')
        ->toContain('data-eb-field="navigation_label"')
        ->toContain('data-eb-field="heading"')
        ->toContain('data-eb-field="item_heading"')
        ->toContain('data-eb-field="item_body"')
        ->toContain('data-eb-field="item_image"')
        ->toContain('href="#"')
        ->and($content)->not->toContain('href="/"');
});

test('the full-site draft preview contains only owned preview links and a return to the exact editor page', function (): void {
    $tenant = managedWebsiteTenant('preview-navigation-pilot');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $home = $site->pages()->where('slug', '/')->firstOrFail();
    $about = TenantSitePage::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_site_id' => $site->id,
        'slug' => 'about',
        'page_type' => 'about',
        'title' => 'About us',
        'is_navigation_visible' => true,
    ]);
    $service->saveDraft($site, $about, ['title' => 'About us', 'blocks' => [['type' => 'text', 'heading' => 'About us', 'body' => 'Local and dependable.']]], $actor);
    $service->saveDraft($site, $home, ['title' => 'Home', 'blocks' => [['type' => 'hero', 'heading' => 'Home', 'body' => 'A safe preview.', 'cta_label' => 'About us', 'cta_url' => '/about'], ['type' => 'cta', 'heading' => 'Book', 'cta_label' => 'Book online', 'cta_url' => 'https://booking.example.test'], ['type' => 'cta', 'heading' => 'Call us', 'cta_label' => 'Call', 'cta_url' => 'tel:+15550100']]], $actor);
    $service->saveSiteDraft($site, ['settings' => ['announcement' => ['enabled' => true, 'text' => 'See more', 'url' => '/about']], 'navigation' => [['label' => 'Home', 'url' => '/', 'type' => 'page'], ['label' => 'About', 'url' => '/about', 'type' => 'page']]], $actor);
    $request = Request::create('/website/editor/'.$home->id.'/preview-site');
    $request->attributes->set('current_tenant', $tenant);

    $response = app(ManagedWebsiteController::class)->previewSite($request, $home, $service);
    $content = $response->getContent();

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($content)->toContain('Private draft preview')
        ->and($content)->toContain(route('managed-website.editor', ['page' => $home]))
        ->and($content)->toContain(route('managed-website.editor.preview.site', ['page' => $about]))
        ->and($content)->toContain('target="_blank" rel="noopener noreferrer"')
        ->and($content)->not->toContain('href="/"');
});

test('draft preview routes do not accept another tenant page', function (): void {
    $tenant = managedWebsiteTenant('preview-owner');
    $otherTenant = managedWebsiteTenant('preview-other');
    $actor = managedWebsiteActor($tenant);
    managedWebsiteActor($otherTenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id, $otherTenant->id]);
    $otherSite = app(ManagedWebsiteService::class)->createSite($otherTenant, $actor);
    $otherPage = $otherSite->pages()->where('slug', '/')->firstOrFail();
    $request = Request::create('/website/editor/'.$otherPage->id.'/preview-site');
    $request->attributes->set('current_tenant', $tenant);

    expect(fn () => app(ManagedWebsiteController::class)->previewSite($request, $otherPage, app(ManagedWebsiteService::class)))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

test('pending billing never opens the editor even when a tenant is allowlisted', function (): void {
    $tenant = managedWebsiteTenant('billing-pending');
    managedWebsiteActor($tenant);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->update(['billing_status' => 'pending_billing']);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);

    expect(app(ManagedWebsiteService::class)->editorEnabledFor($tenant))->toBeFalse();
});

test('starter themes produce distinct safe drafts and hidden sections stay out of a public snapshot', function (): void {
    $tenant = managedWebsiteTenant('theme-pilot');
    $actor = managedWebsiteActor($tenant);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $home = $site->pages()->where('slug', '/')->firstOrFail();

    $service->applyTheme($site, 'hvac-service', $actor);
    $hvac = $home->fresh()->draftVersion->blocks;
    $service->applyTheme($site, 'outdoor-elements', $actor);
    $outdoor = $home->fresh()->draftVersion->blocks;
    $service->applyTheme($site, 'product-showcase', $actor);
    $showcase = $home->fresh()->draftVersion->blocks;

    expect(collect($hvac)->pluck('heading')->implode(' '))->toContain('help')
        ->and(collect($outdoor)->pluck('heading')->implode(' '))->toContain('outdoor')
        ->and(collect($showcase)->pluck('heading')->implode(' '))->toContain('statement piece')
        ->and($hvac)->not->toEqual($outdoor)
        ->and(collect($showcase)->pluck('type'))->not->toContain('interactive_product_viewer')
        ->and($service->sanitizeBlocks([['type' => 'text', 'heading' => 'Private draft', 'hidden' => 'true']]))
        ->toBe([['type' => 'text', 'heading' => 'Private draft', 'hidden' => 'true']]);
});

test('a complete Collins starter pack stays a draft and records its public fact source', function (): void {
    $tenant = managedWebsiteTenant('collins-theme-pilot');
    $actor = managedWebsiteActor($tenant);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);

    $service->applyTheme($site, 'collins-electric', $actor);
    $site->refresh()->load('draftSiteVersion');

    expect($site->status)->toBe('draft')
        ->and($site->public_enabled)->toBeFalse()
        ->and($site->pages()->count())->toBe(6)
        ->and($site->draftSiteVersion?->navigation)->toHaveCount(6)
        ->and($site->draftSiteVersion?->source_manifest[0]['url'] ?? null)->toBe('https://www.whodoyou.com/biz/1731846/collins-upstate-electrical-pendleton-sc')
        ->and(collect($site->pages()->where('slug', '/')->firstOrFail()->fresh()->draftVersion->blocks)->pluck('heading')->implode(' '))->toContain('Electrical work');
});

test('unpublished site-wide theme changes cannot alter the published snapshot', function (): void {
    $tenant = managedWebsiteTenant('site-version-pilot');
    $actor = managedWebsiteActor($tenant);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $service->applyTheme($site, 'hvac-service', $actor);
    $service->publish($site, $actor);

    $publishedThemeName = $site->fresh()->publishedSiteVersion?->settings['theme_name'];
    $service->saveSiteDraft($site->fresh(), [
        'settings' => ['theme_name' => 'Private redesign'],
        'navigation' => [['label' => 'Private page', 'url' => '/private', 'type' => 'page']],
    ], $actor);
    $site->refresh()->load(['draftSiteVersion', 'publishedSiteVersion']);

    expect($site->draftSiteVersion?->settings['theme_name'])->toBe('Private redesign')
        ->and($site->publishedSiteVersion?->settings['theme_name'])->toBe($publishedThemeName)
        ->and($site->publishedSiteVersion?->navigation)->not->toEqual($site->draftSiteVersion?->navigation);
});
