<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Catalog shop', 'slug' => 'catalog-shop']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->get(route('managed-website.products.index', ['tenant' => $this->tenant->slug]))->assertOk();
    $this->catalog = app(WebsiteCatalogService::class);
    $this->data = ['catalog_form' => 1, 'title' => 'Oak chair', 'handle' => 'oak-chair', 'description' => 'Solid oak', 'status' => 'active', 'product_type' => 'physical', 'track_inventory' => true, 'media' => ['https://example.test/chair.jpg'], 'retained_media' => ['https://example.test/chair.jpg'], 'collection_ids' => [], 'variants' => [['title' => 'Natural', 'sku' => 'OAK-N', 'price' => '123.45', 'inventory_quantity' => 5, 'is_available' => true]], 'details' => "Handmade\nOak", 'image_alt' => 'Oak chair photo'];
});

test('all eligible tenants get separate lists and a product editor with photographs', function () {
    $product = $this->catalog->saveProduct($this->site, null, $this->data, $this->actor);
    foreach (['products', 'collections', 'customers', 'orders'] as $entity) {
        $this->get(route('managed-website.'.$entity.'.index'))->assertOk()->assertSeeText(ucfirst($entity))->assertDontSeeText('Edit your live design.');
    }
    $this->get(route('managed-website.products.index'))->assertSee('https://example.test/chair.jpg')->assertSee(route('managed-website.products.edit', $product), false);
    $this->get(route('managed-website.products.edit', $product))->assertOk()->assertSeeText('Pricing & variants', false)->assertSeeText('Media')->assertSeeText('Save product');
    $this->get(route('managed-website.products.create'))->assertOk();
    $this->get(route('managed-website.collections.create'))->assertOk();
});

test('product saves preserve variant identities and update collection membership and inventory', function () {
    $collection = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Seating', 'handle' => 'seating', 'status' => 'active']);
    $this->postJson(route('managed-website.products.store'), $this->data)->assertRedirect();
    $product = WebsiteProduct::query()->where('handle', 'oak-chair')->firstOrFail();
    $id = $product->variants->first()->id;
    $data = $this->data;
    $data['revision'] = 1;
    $data['variants'][0]['id'] = $id;
    $data['variants'][0]['price'] = '246.78';
    $data['variants'][] = ['title' => 'Dark', 'sku' => 'OAK-D', 'price' => '250.00', 'inventory_quantity' => 0, 'is_available' => true];
    $data['collection_ids'] = [$collection->id];
    $this->putJson(route('managed-website.products.update', $product), $data)->assertRedirect();
    expect($product->fresh()->variants->first()->id)->toBe($id)->and($product->fresh()->variants->first()->price_cents)->toBe(24678);
    $public = $this->catalog->publicCatalog($this->site)[0];
    expect($public['collections'])->toBe(['seating'])->and($public['variants'][1]['available'])->toBeFalse()->and($public['details'])->toBe(['Handmade', 'Oak']);
    $this->putJson(route('managed-website.products.update', $product), $data)->assertStatus(409);
    $data['revision'] = 2;
    $this->putJson(route('managed-website.products.update', $product), $data)->assertUnprocessable(); // Cannot silently drop the second stable variant.
    $this->delete(route('managed-website.products.destroy', $product))->assertRedirect();
    expect($this->catalog->publicCatalog($this->site))->toBe([])->and($product->fresh()->variants)->toHaveCount(2);
});

test('foreign products variants and collection memberships are rejected', function () {
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'foreign-catalog']);
    $foreign = WebsiteProduct::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $this->site->id, 'title' => 'Foreign', 'handle' => 'foreign', 'status' => 'active', 'product_type' => 'physical']);
    $variant = $foreign->variants()->create(['tenant_id' => $other->id, 'title' => 'Default', 'price_cents' => 100]);
    $collection = WebsiteCollection::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $this->site->id, 'title' => 'Private collection', 'handle' => 'private', 'status' => 'active']);
    $this->get(route('managed-website.products.edit', $foreign))->assertNotFound();
    $this->get(route('managed-website.collections.edit', $collection))->assertNotFound();
    $data = $this->data;
    $data['collection_ids'] = [$collection->id];
    $this->postJson(route('managed-website.products.store'), $data)->assertUnprocessable();
    $data['collection_ids'] = [];
    $data['variants'][0]['id'] = $variant->id;
    $this->postJson(route('managed-website.products.store'), $data)->assertUnprocessable();
    $this->get(route('managed-website.products.index'))->assertDontSeeText('Foreign');
});

test('collection editing manages membership and rejects stale updates', function () {
    $product = $this->catalog->saveProduct($this->site, null, $this->data, $this->actor);
    $data = ['title' => 'Seating', 'handle' => 'seating', 'status' => 'active', 'product_ids' => [$product->id]];
    $this->postJson(route('managed-website.collections.store'), $data)->assertRedirect();
    $collection = WebsiteCollection::query()->firstOrFail();
    $this->get(route('managed-website.collections.edit', $collection))->assertOk()->assertSeeText('Oak chair');
    $data['revision'] = 1;
    $data['product_ids'] = [];
    $this->putJson(route('managed-website.collections.update', $collection), $data)->assertRedirect();
    expect($collection->fresh()->products)->toHaveCount(0);
    $this->putJson(route('managed-website.collections.update', $collection), $data)->assertStatus(409);
});

test('image uploads roll back on invalid products and draft photos remain private', function () {
    Storage::fake('local');
    $data = $this->data;
    $data['images'] = [UploadedFile::fake()->image('chair.jpg')];
    $data['title'] = '';
    $this->postJson(route('managed-website.products.store'), $data)->assertUnprocessable();
    expect(TenantSiteMedia::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    $data['title'] = 'Draft chair';
    $data['status'] = 'draft';
    $this->postJson(route('managed-website.products.store'), $data)->assertRedirect();
    $media = TenantSiteMedia::query()->firstOrFail();
    $this->get(route('managed-website.catalog.media', $media))->assertOk();
    auth()->forgetGuards();
    $this->get(route('managed-website.catalog.media', $media))->assertNotFound();
    $this->site->update(['public_enabled' => true, 'status' => 'published']);
    WebsiteProduct::query()->where('handle', 'oak-chair')->update(['status' => 'active']);
    $this->get(route('managed-website.catalog.media', $media))->assertOk();
    config()->set('managed_website.public_render_enabled', false);
    $this->get(route('managed-website.catalog.media', $media))->assertNotFound();
});

test('catalog gates freeze active edits and customer editing does not require checkout', function () {
    $product = $this->catalog->saveProduct($this->site, null, $this->data, $this->actor);
    config()->set('managed_website.publishing_enabled', false);
    $data = $this->data;
    $data['variants'][0]['id'] = $product->variants->first()->id;
    $this->putJson(route('managed-website.products.update', $product), $data)->assertStatus(423);
    config()->set('managed_website.commerce_enabled', false);
    $this->get(route('managed-website.customers.create'))->assertOk();
    $this->postJson(route('managed-website.customers.store'), ['first_name' => 'Avery', 'email' => 'avery@example.test'])->assertRedirect();
    $customer = \App\Models\WebsiteCustomer::query()->firstOrFail();
    $this->get(route('managed-website.customers.show', $customer))->assertOk()->assertSeeText('Avery');
    $this->putJson(route('managed-website.customers.update', $customer), ['first_name' => 'Avery updated'])->assertRedirect();
    config()->set('managed_website.editor_enabled', false);
    $this->get(route('managed-website.products.edit', $product))->assertStatus(423);
    $this->putJson(route('managed-website.customers.update', $customer), ['first_name' => 'Blocked'])->assertStatus(423);
});
