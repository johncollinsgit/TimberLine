<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function mobilePreviewProduct(TenantSite $site, string $handle): WebsiteProduct
{
    $product = WebsiteProduct::query()->create(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id,
        'title' => 'Fictional '.$handle, 'handle' => $handle, 'status' => 'active', 'product_type' => 'physical', 'description' => 'PRIVATE DESCRIPTION NOT IN MOBILE CONTRACT']);
    $media = TenantSiteMedia::query()->create(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id,
        'storage_disk' => 'local', 'storage_path' => 'fixture/'.$handle.'.glb', 'file_name' => $handle.'.glb',
        'mime_type' => 'model/gltf-binary', 'file_size' => 100, 'checksum' => hash('sha256', $handle), 'kind' => 'model', 'source' => 'visualization:'.$product->id]);
    $product->update(['service_details' => ['private_note' => 'PRIVATE NOTE', 'visualization' => ['enabled' => true,
        'dimensions_confirmed' => true, 'placement' => 'room', 'model_media_id' => $media->id, 'length_mm' => 900,
        'grip_x' => .5, 'grip_y' => .2, 'grip_z' => .5, 'rotation_x' => 0, 'rotation_y' => 0, 'rotation_z' => 0]]]);

    return $product;
}

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set('app.url', 'https://app.theeverbranch.test');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.test');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    $this->previewTenant = Tenant::query()->create(['name' => 'Preview fixture', 'slug' => 'preview-fixture']);
    $this->previewActor = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->previewActor->tenants()->attach($this->previewTenant->id, ['role' => 'admin', 'membership_active' => true]);
    $this->previewEntitlement = TenantModuleEntitlement::query()->create(['tenant_id' => $this->previewTenant->id,
        'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set('managed_website.editor_tenant_ids', [$this->previewTenant->id]);
    config()->set('product_visualization.enabled', true);
    config()->set('product_visualization.tenant_ids', [$this->previewTenant->id]);
    config()->set('product_visualization.hand_enabled', false);
    $websites = app(ManagedWebsiteService::class);
    $this->previewSite = $websites->createSite($this->previewTenant, $this->previewActor);
    $websites->publish($this->previewSite, $this->previewActor);
    $this->previewSite = $this->previewSite->fresh();
    $this->previewProduct = mobilePreviewProduct($this->previewSite, 'synthetic-barrel');
    $this->previewUrl = '/api/mobile/v1/workspaces/'.$this->previewTenant->slug.'/product-visualizations';
    Sanctum::actingAs($this->previewActor, ['mobile:read']);
});

test('native feed exposes a finite data-only published Website descriptor without writes or provider calls', function (): void {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $response = $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('version', 1)
        ->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $this->previewProduct->id)
        ->assertJsonPath('products.0.descriptor.productId', $this->previewProduct->id)
        ->assertJsonPath('products.0.public_viewer_url', 'https://preview-fixture.theeverbranch.test/products/synthetic-barrel/visualize')
        ->assertJsonPath('allowed_hosts', ['app.theeverbranch.test', 'preview-fixture.theeverbranch.test'])
        ->assertJsonPath('next_cursor', null);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect(array_keys($response->json('products.0.descriptor')))->toEqualCanonicalizing(['version', 'productId', 'title', 'placement', 'lengthMm', 'grip', 'rotationDeg', 'modelUrl', 'variants']);
    expect($response->json('products.0.descriptor.modelUrl'))->toBe('https://app.theeverbranch.test/product-visualization/assets/'.$this->previewProduct->id.'/'.data_get($this->previewProduct->service_details, 'visualization.model_media_id').'.glb');
    expect($response->getContent())->not->toContain('PRIVATE', 'storage_path', 'viewerModuleUrl', 'viewerStyleUrl', 'price_cents');
    expect($writes)->toBe([]);
    Http::assertNothingSent();
});

test('native previews are read-only for active members and reject foreign or inactive membership', function (): void {
    $member = User::factory()->create(['is_active' => true]);
    $member->tenants()->attach($this->previewTenant->id, ['role' => 'member', 'membership_active' => true]);
    Sanctum::actingAs($member, ['mobile:read']);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonCount(1, 'products');
    $foreign = Tenant::query()->create(['name' => 'Foreign preview', 'slug' => 'foreign-preview']);
    $this->getJson('/api/mobile/v1/workspaces/'.$foreign->slug.'/product-visualizations')->assertNotFound();
    $member->tenants()->updateExistingPivot($this->previewTenant->id, ['membership_active' => false]);
    $this->getJson($this->previewUrl)->assertNotFound();
});

test('native preview token scope and active account remain required', function (): void {
    Sanctum::actingAs($this->previewActor, ['mobile:write']);
    $this->getJson($this->previewUrl)->assertForbidden();
    Sanctum::actingAs($this->previewActor, ['mobile:read']);
    $this->previewActor->update(['is_active' => false]);
    $this->getJson($this->previewUrl)->assertUnauthorized();
});

test('default-off rollout entitlement and public-site gates fail closed independently', function (string $gate): void {
    match ($gate) {
        'global' => config()->set('product_visualization.enabled', false),
        'tenant' => config()->set('product_visualization.tenant_ids', []),
        'website_allowlist' => config()->set('managed_website.editor_tenant_ids', []),
        'public_render' => config()->set('managed_website.public_render_enabled', false),
        'unpublished' => $this->previewSite->update(['status' => 'draft']),
        'private' => $this->previewSite->update(['public_enabled' => false]),
        'entitlement' => $this->previewEntitlement->update(['enabled_status' => 'disabled']),
        'app_origin' => config()->set('app.url', 'http://app.theeverbranch.test'),
    };
    $this->getJson($this->previewUrl)->assertNotFound();
})->with(['global', 'tenant', 'website_allowlist', 'public_render', 'unpublished', 'private', 'entitlement', 'app_origin']);

test('editor freeze retains published previews while draft disabled and foreign products never appear', function (): void {
    $draft = mobilePreviewProduct($this->previewSite, 'draft-model');
    $draft->update(['status' => 'draft']);
    $disabled = mobilePreviewProduct($this->previewSite, 'disabled-model');
    $details = $disabled->service_details;
    $details['visualization']['enabled'] = false;
    $disabled->update(['service_details' => $details]);
    $foreign = mobilePreviewProduct($this->previewSite, 'foreign-model');
    $foreignTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-preview']);
    $foreign->update(['tenant_id' => $foreignTenant->id]);
    config()->set('managed_website.editor_enabled', false);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $this->previewProduct->id);
});

test('hand gate suppresses hand descriptors independently without hiding eligible room products', function (): void {
    $hand = mobilePreviewProduct($this->previewSite, 'hand-model');
    $details = $hand->service_details;
    $details['visualization']['placement'] = 'hand';
    $hand->update(['service_details' => $details]);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonCount(1, 'products');
    config()->set('product_visualization.hand_enabled', true);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonCount(2, 'products');
});

test('native previews use bounded stable pagination and reject malformed cursors', function (): void {
    for ($i = 0; $i < 26; $i++) {
        mobilePreviewProduct($this->previewSite, 'page-'.$i);
    }
    $first = $this->getJson($this->previewUrl)->assertOk()->assertJsonCount(25, 'products');
    $second = $this->getJson($this->previewUrl.'?cursor='.$first->json('next_cursor'))->assertOk()->assertJsonCount(2, 'products')->assertJsonPath('next_cursor', null);
    expect(array_intersect(array_column($first->json('products'), 'id'), array_column($second->json('products'), 'id')))->toBe([]);
    $this->getJson($this->previewUrl.'?cursor=foreign/../1')->assertUnprocessable();
});

test('bootstrap advertises only the currently available read-only preview capability', function (): void {
    $bootstrap = '/api/mobile/v1/workspaces/'.$this->previewTenant->slug.'/bootstrap';
    $this->getJson($bootstrap)->assertOk()->assertJsonPath('product_visualization.available', true);
    config()->set('product_visualization.enabled', false);
    $this->getJson($bootstrap)->assertOk()->assertJsonPath('product_visualization.available', false);
});

test('native viewer URLs use only active verified routing and honor an included-domain-only setup', function (): void {
    TenantSiteDomain::query()->create(['tenant_id' => $this->previewTenant->id, 'tenant_site_id' => $this->previewSite->id,
        'hostname' => 'reviewed-shop.example', 'status' => 'pending', 'is_primary' => true, 'verification_token' => 'synthetic-not-a-real-token']);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('allowed_hosts', ['app.theeverbranch.test', 'preview-fixture.theeverbranch.test']);
    $domain = TenantSiteDomain::query()->where('tenant_site_id', $this->previewSite->id)->firstOrFail();
    $domain->update(['status' => 'active', 'verified_at' => now(), 'activated_at' => now()]);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('allowed_hosts', ['app.theeverbranch.test', 'reviewed-shop.example'])
        ->assertJsonPath('products.0.public_viewer_url', 'https://reviewed-shop.example/products/synthetic-barrel/visualize');
    $this->previewSite->setup()->create(['tenant_id' => $this->previewTenant->id, 'domain_choice' => 'everbranch_subdomain']);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('allowed_hosts', ['app.theeverbranch.test', 'preview-fixture.theeverbranch.test']);
});

test('connected renderer sites cannot advertise the native Website public viewer route', function (): void {
    $this->previewTenant->update(['slug' => 'carolina-barrel-co']);
    $this->previewSite->update(['settings' => ['connected_renderer' => 'carolina_barrel_v1']]);
    $this->getJson('/api/mobile/v1/workspaces/carolina-barrel-co/product-visualizations')->assertNotFound();
    $this->getJson('/api/mobile/v1/workspaces/carolina-barrel-co/bootstrap')->assertOk()->assertJsonPath('product_visualization.available', false);
});

test('a published status without the actual published home snapshot cannot advertise a viewer', function (): void {
    $this->previewSite->pages()->update(['published_version_id' => null]);
    $this->getJson($this->previewUrl)->assertNotFound();
});
