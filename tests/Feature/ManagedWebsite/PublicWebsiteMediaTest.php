<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteMediaUrls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
    expect($url)->toBe('https://app.theeverbranch.com/website-media/'.$id);
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
    $this->get('https://image-studio.theeverbranch.com/')->assertOk()->assertSee('src="'.$path.'"', false)->assertSee("url('".$path."')", false)->assertDontSee($url, false);
    $this->get('https://image-studio.theeverbranch.com'.$path)->assertOk()->assertHeader('Content-Type', 'image/png')->assertStreamedContent($bytes);
    $this->call('GET', 'https://app.theeverbranch.com/', [], [], [], imageRelayHeaders($this->domain->hostname, '/'))->assertOk()->assertSee('src="'.$path.'"', false)->assertDontSee($url, false);
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
    expect($product->media)->toBe(['https://app.theeverbranch.com'.$path]);
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
