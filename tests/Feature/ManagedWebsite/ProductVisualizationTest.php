<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\ProductVisualizationService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function visualizationGlb(array $changes = []): string
{
    $json = json_encode(array_merge(['asset' => ['version' => '2.0'], 'meshes' => [['primitives' => []]]], $changes));
    $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');

    return pack('VVVVV', 0x46546C67, 2, 20 + strlen($json), strlen($json), 0x4E4F534A).$json;
}

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->tenant = Tenant::query()->create(['name' => 'Visualization fixture', 'slug' => 'visual-fixture']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id]);
    config()->set('product_visualization.enabled', true);
    config()->set('product_visualization.hand_enabled', true);
    config()->set('product_visualization.tenant_ids', [$this->tenant->id]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->product = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Fictional knife', 'handle' => 'fictional-knife', 'status' => 'active', 'product_type' => 'physical', 'service_details' => ['catalog_revision' => 0]]);
    $this->variant = $this->product->variants()->create(['tenant_id' => $this->tenant->id, 'title' => 'Blue / Maple', 'price_cents' => 100, 'is_available' => true]);
    Storage::disk('local')->put('fixtures/model.glb', visualizationGlb());
    $this->media = TenantSiteMedia::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'uploaded_by_user_id' => $this->actor->id, 'storage_disk' => 'local', 'storage_path' => 'fixtures/model.glb', 'file_name' => 'model.glb', 'mime_type' => 'model/gltf-binary', 'file_size' => 100, 'checksum' => hash('sha256', visualizationGlb()), 'kind' => 'model', 'source' => 'visualization:'.$this->product->id]);
    $this->input = ['revision' => 0, 'enabled' => true, 'placement' => 'hand', 'model_media_id' => $this->media->id, 'length_mm' => 300, 'dimensions_confirmed' => true, 'grip_x' => .5, 'grip_y' => .2, 'grip_z' => .5, 'rotation_x' => 0, 'rotation_y' => 0, 'rotation_z' => 0, 'variant_models' => [['variant_id' => $this->variant->id, 'media_id' => $this->media->id]]];
    $this->visuals = app(ProductVisualizationService::class);
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->get(route('managed-website.products.index', ['tenant' => $this->tenant->slug]))->assertOk();
});

test('tenant editor saves measured hand and room models without changing product commerce', function () {
    $this->get(route('managed-website.products.visualization.edit', $this->product))->assertOk()->assertSeeText('Product visualization');
    $this->put(route('managed-website.products.visualization.update', $this->product), $this->input)->assertRedirect();
    $saved = $this->product->fresh();
    expect(data_get($saved->service_details, 'visualization.placement'))->toBe('hand')->and($this->variant->fresh()->price_cents)->toBe(100);
    $this->input['revision'] = 1;
    $this->input['placement'] = 'room';
    $this->put(route('managed-website.products.visualization.update', $this->product), $this->input)->assertRedirect();
    expect(data_get($this->product->fresh()->service_details, 'visualization.placement'))->toBe('room');
});

test('feature and tenant gates are independent and empty does not mean every tenant', function () {
    config()->set('product_visualization.tenant_ids', []);
    $this->get(route('managed-website.products.visualization.edit', $this->product))->assertNotFound();
    config()->set('product_visualization.tenant_ids', [$this->tenant->id]);
    config()->set('product_visualization.enabled', false);
    $this->putJson(route('managed-website.products.visualization.update', $this->product), $this->input)->assertNotFound();
});

test('stale catalog revisions and the publishing freeze reject updates', function () {
    $this->visuals->save($this->site, $this->product, $this->actor, $this->input);
    $this->putJson(route('managed-website.products.visualization.update', $this->product), $this->input)->assertConflict();
    $this->input['revision'] = 1;
    config()->set('managed_website.publishing_enabled', false);
    $this->putJson(route('managed-website.products.visualization.update', $this->product), $this->input)->assertStatus(423);
});

test('product scoped models and stable variants cannot be borrowed from another product', function () {
    $other = $this->product->replicate();
    $other->handle = 'another-product';
    $other->save();
    $this->input['model_media_id'] = $this->media->id;
    $this->putJson(route('managed-website.products.visualization.update', $other), $this->input)->assertUnprocessable();
    $this->get(route('product-visualization.asset', ['product' => $other, 'media' => $this->media]))->assertNotFound();
    $foreign = Tenant::query()->create(['name' => 'Other tenant', 'slug' => 'visual-foreign']);
    $other->update(['tenant_id' => $foreign->id]);
    $this->get(route('managed-website.products.visualization.edit', $other))->assertNotFound();
});

test('hand mode is independently gated and incomplete dimensions never activate it', function () {
    config()->set('product_visualization.hand_enabled', false);
    $this->putJson(route('managed-website.products.visualization.update', $this->product), $this->input)->assertUnprocessable();
    $this->input['placement'] = 'room';
    $this->input['dimensions_confirmed'] = false;
    $this->putJson(route('managed-website.products.visualization.update', $this->product), $this->input)->assertUnprocessable();
});

test('only selected models on published active products become public', function () {
    $saved = $this->visuals->save($this->site, $this->product, $this->actor, $this->input);
    $this->site->update(['status' => 'published', 'public_enabled' => true]);
    $url = route('product-visualization.asset', ['product' => $saved, 'media' => $this->media]);
    $this->app['auth']->forgetGuards();
    auth()->logout();
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'model/gltf-binary')->assertHeader('Access-Control-Allow-Origin', '*');
    expect($this->visuals->descriptor($this->site, $saved)['variants'][0]['label'])->toBe('Blue / Maple');
    $extra = $this->media->replicate();
    $extra->storage_path = 'fixtures/extra.glb';
    $extra->save();
    $this->get(route('product-visualization.asset', ['product' => $saved, 'media' => $extra]))->assertNotFound();
    $saved->update(['status' => 'draft']);
    $this->get($url)->assertNotFound();
    expect($this->visuals->descriptor($this->site, $saved))->toBeNull();
});

test('connected catalogs carry the same gated visualization contract', function () {
    $saved = $this->visuals->save($this->site, $this->product, $this->actor, $this->input);
    $this->site->update(['status' => 'published', 'public_enabled' => true]);
    $catalog = app(WebsiteCatalogService::class)->publicCatalog($this->site);
    expect($catalog[0]['visualization']['productId'])->toBe($saved->id)->and($catalog[0]['visualization']['lengthMm'])->toBe(300.0);
    config()->set('product_visualization.enabled', false);
    expect(app(WebsiteCatalogService::class)->publicCatalog($this->site)[0]['visualization'])->toBeNull();
});

test('freezing the editor does not unpublish an already approved visualization', function () {
    $saved = $this->visuals->save($this->site, $this->product, $this->actor, $this->input);
    $this->site->update(['status' => 'published', 'public_enabled' => true]);
    config()->set('managed_website.editor_enabled', false);
    expect($this->visuals->descriptor($this->site, $saved))->not->toBeNull();
    $this->putJson(route('managed-website.products.visualization.update', $this->product), $this->input)->assertStatus(423);
    config()->set('managed_website.public_render_enabled', false);
    expect($this->visuals->descriptor($this->site, $saved))->toBeNull();
});

test('self contained GLB upload is product scoped and never silently enabled', function () {
    $file = UploadedFile::fake()->createWithContent('barrel.glb', visualizationGlb());
    $this->post(route('managed-website.products.visualization.upload', $this->product), ['model' => $file])->assertRedirect();
    expect($this->visuals->assets($this->product)->count())->toBe(2)->and(data_get($this->product->fresh()->service_details, 'visualization'))->toBeNull();
});

test('GLB header external references required codecs and excessive geometry are rejected', function () {
    foreach (['plain text', visualizationGlb(['buffers' => [['uri' => 'https://other.test/private.bin']]]), visualizationGlb(['images' => [['uri' => 'file:///secret']]]), visualizationGlb(['extensionsRequired' => ['KHR_draco_mesh_compression']]), visualizationGlb(['accessors' => [['count' => 3000000]]])] as $bad) {
        expect(fn () => $this->visuals->validateGlb($bad))->toThrow(ValidationException::class);
    }
});
