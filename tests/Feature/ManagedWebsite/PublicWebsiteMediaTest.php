<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteProduct;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteMediaUrls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->tenant = Tenant::query()->create(['name' => 'Fictional image studio', 'slug' => 'image-studio']);
    $this->owner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial']);
    config()->set([
        'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.custom_domains_enabled' => true,
        'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.custom_domain_tenant_ids' => [$this->tenant->id],
        'managed_website.relay.enabled' => true, 'managed_website.relay.secret' => str_repeat('fictional-image-relay-', 3),
    ]);
    $this->websites = app(ManagedWebsiteService::class);
    $this->site = $this->websites->createSite($this->tenant, $this->owner);
    $this->domain = TenantSiteDomain::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'hostname' => 'www.image-studio.example', 'status' => 'active', 'is_primary' => true, 'verification_token' => 'fictional-proof']);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
});

test('logo and social photos use only current published site and page snapshots', function (): void {
    $images = [];
    for ($index = 0; $index < 4; $index++) {
        $images[] = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, ['image' => UploadedFile::fake()->image('slot-'.$index.'.png')])->assertCreated()->json('media');
    }
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->websites->publish($this->site, $this->owner);
    $this->websites->saveSiteDraft($this->site, ['settings' => ['logo_url' => $images[0]['url'], 'logo_alt' => 'Fictional logo'], 'seo' => ['social_image' => $images[2]['url'], 'social_image_alt' => 'Site social photo']], $this->owner);
    $this->websites->saveDraft($this->site, $home, ['title' => 'Private social image', 'blocks' => [['type' => 'hero', 'heading' => 'Fictional image studio']], 'seo' => ['social_image' => $images[1]['url'], 'social_image_alt' => 'Page social photo']], $this->owner);
    auth()->forgetGuards();
    $this->flushSession();
    foreach ($images as $image) {
        $this->get('https://image-studio.theeverbranch.com/website-media/'.$image['id'])->assertNotFound();
    }
    $this->websites->publish($this->site->fresh(), $this->owner);
    $this->get('https://image-studio.theeverbranch.com/')->assertOk()->assertSee('alt="Fictional logo"', false)->assertSee('content="Page social photo"', false);
    foreach (array_slice($images, 0, 3) as $image) {
        $path = '/website-media/'.$image['id'];
        $this->get('https://image-studio.theeverbranch.com'.$path)->assertOk();
        $this->call('GET', 'https://app.theeverbranch.com'.$path, [], [], [], imageRelayHeaders($this->domain->hostname, $path))->assertOk();
        $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    }
    $this->get('https://image-studio.theeverbranch.com/website-media/'.$images[3]['id'])->assertNotFound();
    $this->websites->saveSiteDraft($this->site->fresh(), ['settings' => ['logo_url' => ''], 'seo' => ['social_image' => '']], $this->owner);
    $this->websites->saveDraft($this->site->fresh(), $home->fresh(), ['title' => 'Photos removed', 'blocks' => [['type' => 'hero', 'heading' => 'Fictional image studio']], 'seo' => []], $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    foreach ($images as $image) {
        $this->get('https://image-studio.theeverbranch.com/website-media/'.$image['id'])->assertNotFound();
    }
    Http::assertNothingSent();
});

test('paid sites outside pilot allowlists serve only their active collection photo and recover safely', function (): void {
    \App\Models\TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['self_service_registration' => true]]);
    $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
    $purchases->shouldReceive('paidForTenant')->andReturn(true);
    $purchases->shouldReceive('hasVerifiedPurchase')->andReturn(true);
    app()->instance(SelfServicePurchaseEligibility::class, $purchases);
    config()->set('managed_website.editor_tenant_ids', []);
    app(\App\Services\ManagedWebsite\WebsitePilotService::class)->saveSetup($this->tenant, $this->site, ['contact_name' => 'Fictional image studio', 'contact_email' => 'fixture@example.test'], $this->owner);
    $selected = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, ['image' => UploadedFile::fake()->image('collection.png')])->assertCreated()->json('media');
    $unused = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, ['image' => UploadedFile::fake()->image('unused.png')])->assertCreated()->json('media');
    $this->post('https://app.theeverbranch.com/website/collections?tenant='.$this->tenant->slug, ['title' => 'Fictional collection', 'handle' => 'fictional', 'status' => 'active', 'image_url' => $selected['catalog_url'], 'image_alt' => 'Owned collection photo'])->assertRedirect()->assertSessionHasNoErrors();
    $collection = WebsiteCollection::query()->where('tenant_id', $this->tenant->id)->sole();
    expect(data_get($collection->seo, 'image_alt'))->toBe('Owned collection photo');
    $this->websites->publish($this->site->fresh(), $this->owner);
    auth()->forgetGuards();
    $this->flushSession();
    $path = '/website-catalog-media/'.$selected['id'];
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertOk();
    $this->call('GET', 'https://app.theeverbranch.com'.$path, [], [], [], imageRelayHeaders($this->domain->hostname, $path))->assertOk();
    $this->get('https://image-studio.theeverbranch.com/website-catalog-media/'.$unused['id'])->assertNotFound();
    $this->get('https://image-studio.theeverbranch.com/website-media/'.$selected['id'])->assertNotFound();
    $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    $collection->update(['image_url' => 'https://foreign.example'.$path]);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    $collection->update(['image_url' => $selected['catalog_url'], 'status' => 'draft']);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    $collection->update(['status' => 'active']);
    config()->set('managed_website.public_render_enabled', false);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    Http::assertNothingSent();
});

test('new logo and social fields cannot reference another website upload', function (): void {
    $other = Tenant::query()->create(['name' => 'Other photo owner', 'slug' => 'other-photo-owner']);
    $otherSite = $this->websites->createSite($other, null);
    $foreign = TenantSiteMedia::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $otherSite->id, 'storage_disk' => 'local', 'storage_path' => 'foreign.png', 'file_name' => 'foreign.png', 'mime_type' => 'image/png', 'file_size' => 1, 'checksum' => str_repeat('a', 64), 'kind' => 'image', 'source' => 'upload']);
    $siteVersion = $this->site->fresh()->draft_site_version_id;
    $this->putJson('https://app.theeverbranch.com/website/editor/theme?tenant='.$this->tenant->slug, ['current_version_id' => $siteVersion, 'settings' => ['logo_url' => '/website-media/'.$foreign->id], 'navigation' => $this->site->fresh()->draftSiteVersion->navigation])->assertUnprocessable()->assertJsonValidationErrors('settings.logo_url');
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $pageVersion = $home->draft_version_id;
    $this->putJson('https://app.theeverbranch.com/website/editor/'.$home->id.'?tenant='.$this->tenant->slug, ['current_version_id' => $pageVersion, 'title' => 'Denied photo', 'blocks' => [['type' => 'hero', 'heading' => 'No foreign photo']], 'seo' => ['social_image' => '/website-media/'.$foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('seo.social_image');
    expect($this->site->fresh()->draft_site_version_id)->toBe($siteVersion)->and($home->fresh()->draft_version_id)->toBe($pageVersion);
});

function imageRelayHeaders(string $host, string $path): array
{
    $stamp = (string) now()->timestamp;
    $message = implode("\n", ['v1', $stamp, 'GET', $host, $path, hash('sha256', '')]);

    return ['HTTP_X_EVERBRANCH_WEBSITE_HOST' => $host, 'HTTP_X_EVERBRANCH_WEBSITE_TIMESTAMP' => $stamp,
        'HTTP_X_EVERBRANCH_WEBSITE_SIGNATURE' => hash_hmac('sha256', $message, config('managed_website.relay.secret'))];
}

test('real app editor upload renders and serves the same image anonymously on included and relayed custom hosts', function (): void {
    $upload = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, ['image' => UploadedFile::fake()->image('fictional-photo.png', 16, 16), 'alt_text' => 'Fictional transport picture'])->assertCreated();
    $url = $upload->json('media.url');
    $id = $upload->json('media.id');
    expect($url)->toBe('/website-media/'.$id);
    $media = TenantSiteMedia::query()->findOrFail($id);
    $bytes = Storage::disk('local')->get($media->storage_path);
    $privatePreview = $this->get($url)->assertOk()->assertStreamedContent($bytes);
    expect($privatePreview->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->websites->saveDraft($this->site, $home, ['title' => 'Fictional studio', 'blocks' => [
        ['type' => 'hero', 'heading' => 'Fictional studio', 'image_url' => $url],
        ['type' => 'gallery', 'items' => [['image_url' => $url, 'image_alt' => 'Fictional picture']]],
    ]], $this->owner);
    $this->websites->publish($this->site, $this->owner);
    auth()->forgetGuards();
    $this->flushSession();
    $path = '/website-media/'.$id;
    $this->get('https://image-studio.theeverbranch.com/')->assertOk()->assertSee('src="'.$path.'"', false)->assertSee("url('".$path."')", false)->assertDontSee('https://app.theeverbranch.com'.$url, false);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertOk()->assertHeader('Content-Type', 'image/png')->assertStreamedContent($bytes);
    $this->call('GET', 'https://app.theeverbranch.com/', [], [], [], imageRelayHeaders($this->domain->hostname, '/'))->assertOk()->assertSee('src="'.$path.'"', false)->assertDontSee('https://app.theeverbranch.com'.$url, false);
    $response = $this->call('GET', 'https://app.theeverbranch.com'.$path, [], [], [], imageRelayHeaders($this->domain->hostname, $path));
    $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertStreamedContent($bytes);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    $this->call('GET', 'https://app.theeverbranch.com/website', [], [], [], imageRelayHeaders($this->domain->hostname, '/website'))->assertNotFound();
    config()->set('managed_website.public_render_enabled', false);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    Http::assertNothingSent();
});

test('catalog upload audience uses its exact site-owned reference across editor included and custom hosts', function (): void {
    $data = ['catalog_form' => 1, 'title' => 'Fictional photograph', 'handle' => 'fictional-photograph', 'description' => 'Internal image test', 'status' => 'active', 'product_type' => 'quote', 'track_inventory' => false, 'retained_media' => [], 'collection_ids' => [], 'variants' => [['title' => 'Default', 'price' => '0', 'inventory_quantity' => 0, 'is_available' => true]], 'images' => [UploadedFile::fake()->image('catalog.png', 16, 16)]];
    $this->postJson('https://app.theeverbranch.com/website/products?tenant='.$this->tenant->slug, $data)->assertRedirect();
    $product = WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $media = TenantSiteMedia::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $path = '/website-catalog-media/'.$media->id;
    expect($product->media)->toBe([$path]);
    $bytes = Storage::disk('local')->get($media->storage_path);
    $this->get('https://app.theeverbranch.com'.$path)->assertOk(); // Owner's private editor remains available.
    auth()->forgetGuards();
    $this->flushSession();
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    $this->websites->saveSiteDraft($this->site, ['settings' => ['theme_key' => 'sawyer-naturals']], $this->owner);
    $this->websites->publish($this->site, $this->owner);
    $this->get('https://image-studio.theeverbranch.com/shop')->assertOk()->assertSee('src="'.$path.'"', false)->assertDontSee('https://app.theeverbranch.com'.$path, false);
    $productPath = '/products/fictional-photograph';
    $this->call('GET', 'https://app.theeverbranch.com'.$productPath, [], [], [], imageRelayHeaders($this->domain->hostname, $productPath))
        ->assertOk()->assertSee('src="'.$path.'"', false)->assertDontSee('https://app.theeverbranch.com'.$path, false);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertOk()->assertStreamedContent($bytes);
    $this->call('GET', 'https://app.theeverbranch.com'.$path, [], [], [], imageRelayHeaders($this->domain->hostname, $path))->assertOk()->assertStreamedContent($bytes);
    $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    $other = Tenant::query()->create(['name' => 'Other catalog', 'slug' => 'other-image-catalog']);
    $otherSite = $this->websites->createSite($other, null);
    $this->websites->publish($otherSite, null);
    $this->get('https://other-image-catalog.theeverbranch.com'.$path)->assertNotFound();
    $product->update(['media' => ['https://untrusted.example'.$path]]);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    $product->update(['media' => ['https://app.theeverbranch.com'.$path], 'status' => 'draft']);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    $this->get('https://image-studio.theeverbranch.com/website-media/'.$media->id)->assertNotFound();
});

test('a new draft upload on an already published site remains private until that image is published', function (): void {
    $this->websites->publish($this->site, $this->owner);
    $upload = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, ['image' => UploadedFile::fake()->image('draft-only.png')])->assertCreated();
    $url = $upload->json('media.url');
    $path = '/website-media/'.$upload->json('media.id');
    $this->get($url)->assertOk();
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->websites->saveDraft($this->site, $home, ['title' => 'New private image', 'blocks' => [['type' => 'hero', 'heading' => 'Still draft', 'image_url' => $url]]], $this->owner);
    auth()->forgetGuards();
    $this->flushSession();
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound();
    $this->call('GET', 'https://app.theeverbranch.com'.$path, [], [], [], imageRelayHeaders($this->domain->hostname, $path))->assertNotFound();
    $this->websites->publish($this->site, $this->owner);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertOk();
});

test('upload references never adopt foreign identifiers hosts credentials or malformed paths', function (): void {
    $upload = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, ['image' => UploadedFile::fake()->image('private.png')])->assertCreated();
    $path = '/website-media/'.$upload->json('media.id');
    $urls = app(WebsiteMediaUrls::class);
    foreach (['https://app.theeverbranch.com'.$path, 'https://image-studio.theeverbranch.com'.$path, 'https://www.image-studio.example'.$path, $path] as $url) {
        expect($urls->forSite($this->site, $url))->toBe($path);
    }
    foreach (['https://unknown.example'.$path, 'https://app.theeverbranch.com:443'.$path, 'https://user@app.theeverbranch.com'.$path, 'https://app.theeverbranch.com'.$path.'?token=x', 'https://app.theeverbranch.com/website-media/%31', '//app.theeverbranch.com'.$path] as $url) {
        expect($urls->forSite($this->site, $url))->toBe('');
    }
    expect($urls->forSite($this->site, '/images/public-site/guide-studios.webp'))->toBe('/images/public-site/guide-studios.webp')
        ->and($urls->forSite($this->site, 'https://photos.example/stock.jpg'))->toBe('https://photos.example/stock.jpg');
    $other = Tenant::query()->create(['name' => 'Other fictional studio', 'slug' => 'other-image-studio']);
    $otherSite = $this->websites->createSite($other, null);
    expect($urls->forSite($otherSite, 'https://app.theeverbranch.com'.$path))->toBe('');
    auth()->forgetGuards();
    $this->flushSession();
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertNotFound(); // Unpublished upload.
    $this->websites->publish($otherSite, null);
    $this->get('https://other-image-studio.theeverbranch.com'.$path)->assertNotFound();
});

test('owned picker uploads stay relative through catalog validation and hero rendering on a development port', function (): void {
    $origin = 'http://app.theeverbranch.com:5196';
    URL::forceRootUrl($origin);
    try {
        $photo = $this->postJson($origin.'/website/media?tenant='.$this->tenant->slug, [
            'image' => UploadedFile::fake()->image('owned-port-photo.png'), 'alt_text' => 'Owned port-safe photo',
        ])->assertCreated()->json('media');
        expect($photo['url'])->toBe('/website-media/'.$photo['id'])
            ->and($photo['catalog_url'])->toBe('/website-catalog-media/'.$photo['id']);
        $this->getJson($origin.'/website/media?tenant='.$this->tenant->slug)
            ->assertOk()->assertJsonPath('media.0.url', $photo['url'])->assertJsonPath('media.0.catalog_url', $photo['catalog_url']);
        $this->get($origin.'/website/products/create?tenant='.$this->tenant->slug)->assertOk()
            ->assertViewHas('photoLibrary', fn ($rows): bool => $rows[0]['url'] === $photo['url'] && $rows[0]['catalog_url'] === $photo['catalog_url']);
        $this->post($origin.'/website/products?tenant='.$this->tenant->slug, [
            'catalog_form' => 1, 'title' => 'Fictional port-safe product', 'handle' => 'port-safe-product',
            'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => false,
            'retained_media' => [$photo['catalog_url']], 'collection_ids' => [],
            'variants' => [['title' => 'Default', 'price' => '12.00', 'is_available' => true]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->sole()->media)->toBe([$photo['catalog_url']]);
        $this->post($origin.'/website/collections?tenant='.$this->tenant->slug, [
            'title' => 'Port-safe collection', 'handle' => 'port-safe-collection', 'status' => 'draft',
            'image_url' => $photo['catalog_url'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect(WebsiteCollection::query()->where('tenant_id', $this->tenant->id)->sole()->image_url)->toBe($photo['catalog_url']);
        $home = $this->site->pages()->where('slug', '/')->firstOrFail();
        $this->putJson($origin.'/website/editor/'.$home->id.'?tenant='.$this->tenant->slug, [
            'current_version_id' => $home->draft_version_id, 'title' => 'Port-safe private draft',
            'blocks' => [['type' => 'hero', 'heading' => 'Port-safe hero', 'image_url' => $photo['url'], 'image_alt' => 'Owned port-safe photo']],
        ])->assertOk();
        $this->get($origin.'/website/editor/'.$home->id.'/preview-site?tenant='.$this->tenant->slug)
            ->assertOk()->assertSee("url('".$photo['url']."')", false);
        expect(app(WebsiteMediaUrls::class)->forSite($this->site, $origin.$photo['url']))->toBe('');
        Http::assertNothingSent();
    } finally {
        URL::forceRootUrl(null);
    }
});

test('multipart catalog uploads persist the same relative owned catalog alias', function (): void {
    URL::forceRootUrl('http://app.theeverbranch.com:5196');
    try {
        $this->post('http://app.theeverbranch.com:5196/website/products?tenant='.$this->tenant->slug, [
            'catalog_form' => 1, 'title' => 'Uploaded catalog product', 'handle' => 'uploaded-port-product',
            'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => false,
            'retained_media' => [], 'collection_ids' => [], 'images' => [UploadedFile::fake()->image('catalog-port.png')],
            'variants' => [['title' => 'Default', 'price' => '10.00', 'is_available' => true]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $media = TenantSiteMedia::query()->where('tenant_id', $this->tenant->id)->sole();
        expect(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->sole()->media)->toBe(['/website-catalog-media/'.$media->id]);
        Http::assertNothingSent();
    } finally {
        URL::forceRootUrl(null);
    }
});

test('approved related photos retain their relative path through normal HTTPS catalog forms', function (): void {
    $path = '/images/public-site/guide-retail.webp';
    expect(is_file(public_path(ltrim($path, '/'))))->toBeTrue();
    $this->post('https://app.theeverbranch.com/website/products?tenant='.$this->tenant->slug, [
        'catalog_form' => 1, 'title' => 'Related-photo product', 'handle' => 'related-photo-product',
        'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => false,
        'retained_media' => [$path], 'collection_ids' => [],
        'variants' => [['title' => 'Default', 'price' => '10.00', 'is_available' => true]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post('https://app.theeverbranch.com/website/collections?tenant='.$this->tenant->slug, [
        'title' => 'Related-photo collection', 'handle' => 'related-photo-collection', 'status' => 'draft', 'image_url' => $path,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->sole()->media)->toBe([$path])
        ->and(WebsiteCollection::query()->where('tenant_id', $this->tenant->id)->sole()->image_url)->toBe($path);
    Http::assertNothingSent();
});

test('catalog photo strings still reject foreign ownership and noncanonical upload URLs', function (string $case): void {
    $photo = $this->postJson('https://app.theeverbranch.com/website/media?tenant='.$this->tenant->slug, [
        'image' => UploadedFile::fake()->image('allowed-photo.png'),
    ])->assertCreated()->json('media');
    $path = $photo['catalog_url'];
    if (in_array($case, ['foreign tenant', 'foreign site'], true)) {
        $other = Tenant::query()->create(['name' => 'Other image owner', 'slug' => 'other-catalog-image']);
        $otherSite = $this->websites->createSite($other, null);
        $foreign = TenantSiteMedia::query()->create([
            'tenant_id' => $case === 'foreign tenant' ? $other->id : $this->tenant->id,
            'tenant_site_id' => $otherSite->id, 'storage_disk' => 'local', 'storage_path' => 'foreign.png',
            'file_name' => 'foreign.png', 'mime_type' => 'image/png', 'file_size' => 1,
            'checksum' => str_repeat('a', 64), 'kind' => 'image', 'source' => 'upload',
        ]);
        $path = '/website-catalog-media/'.$foreign->id;
    } else {
        $path = match ($case) {
            'development port' => 'http://app.theeverbranch.com:5196'.$path,
            'default explicit port' => 'https://app.theeverbranch.com:443'.$path,
            'scheme relative' => '//app.theeverbranch.com'.$path,
            'query' => $path.'?download=1',
            'unrelated relative' => '/private-photo.png',
            'unsupported scheme' => 'javascript:alert(1)',
        };
    }
    $this->postJson('https://app.theeverbranch.com/website/products?tenant='.$this->tenant->slug, [
        'catalog_form' => 1, 'title' => 'Denied photo', 'handle' => 'denied-photo-product',
        'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => false,
        'retained_media' => [$path], 'collection_ids' => [],
        'variants' => [['title' => 'Default', 'price' => '10.00', 'is_available' => true]],
    ])->assertUnprocessable()->assertJsonValidationErrors('media.0');
    $this->postJson('https://app.theeverbranch.com/website/collections?tenant='.$this->tenant->slug, [
        'title' => 'Denied collection', 'handle' => 'denied-photo-collection', 'status' => 'draft', 'image_url' => $path,
    ])->assertUnprocessable()->assertJsonValidationErrors('image_url');
    expect(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(WebsiteCollection::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with(['foreign tenant', 'foreign site', 'development port', 'default explicit port', 'scheme relative', 'query', 'unrelated relative', 'unsupported scheme']);
