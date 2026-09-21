<?php

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Carolina Barrel', 'slug' => 'carolina-barrel-co']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->service = app(ConnectedWebsiteService::class);
    $this->service->connect($this->site, $this->actor);
    $this->site->refresh();
});

test('connected baseline import is repeatable and public data is immutable until publish', function () {
    $initial = $this->site->published_site_version_id;
    $count = $this->site->siteVersions()->count();
    $this->service->connect($this->site, $this->actor);
    expect($this->site->siteVersions()->count())->toBe($count)->and(WebsiteProduct::query()->forTenant($this->tenant)->count())->toBe(5);
    expect(WebsiteProduct::query()->forTenant($this->tenant)->first()->media)->not->toBeEmpty();
    $content = $this->service->content($this->site, $this->site->draft_site_version_id);
    $key = array_key_first(array_filter($this->service->manifest()['fields'], fn ($field) => $field['type'] === 'text'));
    $content[$key] = 'PRIVATE DRAFT ONLY';
    $draft = $this->service->save($this->site, $content, $this->actor, $this->site->draft_site_version_id);
    $this->getJson('/api/connected-website/carolina-barrel/content')->assertOk()->assertDontSee('PRIVATE DRAFT ONLY');
    $this->site->refresh();
    parse_str(parse_url($this->service->previewUrl($this->site, $this->actor), PHP_URL_QUERY), $query);
    $this->getJson('/api/connected-website/carolina-barrel/content?preview='.urlencode($query['__preview']))->assertOk()->assertSee('PRIVATE DRAFT ONLY')->assertHeader('Cache-Control', 'no-store, private');
    $this->service->publish($this->site, $this->actor, $draft->id);
    $this->getJson('/api/connected-website/carolina-barrel/content')->assertOk()->assertSee('PRIVATE DRAFT ONLY');
    expect($this->service->content($this->site, $initial)[$key])->not->toBe('PRIVATE DRAFT ONLY');
    expect(app(ManagedWebsiteService::class)->publicPage($this->tenant, '/'))->toBeNull();
});

test('preview tokens expire and reject revoked membership', function () {
    parse_str(parse_url($this->service->previewUrl($this->site, $this->actor), PHP_URL_QUERY), $query);
    $url = '/api/connected-website/carolina-barrel/content?preview='.urlencode($query['__preview']);
    $this->travel(16)->minutes();
    $this->getJson($url)->assertForbidden();
    $this->travelBack();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->getJson($url)->assertForbidden();
    $this->getJson('/api/connected-website/carolina-barrel/content?preview=bad-token')->assertForbidden();
});

test('content freeze and public disable gates are independent and fail closed', function () {
    config()->set('managed_website.editor_enabled', false);
    $this->getJson('/api/connected-website/carolina-barrel/content')->assertOk();
    expect(fn () => $this->service->save($this->site, $this->service->manifest()['defaults'], $this->actor, $this->site->draft_site_version_id))->toThrow(HttpException::class);
    config()->set('managed_website.public_render_enabled', false);
    $this->getJson('/api/connected-website/carolina-barrel/content')->assertStatus(423);
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', [])->assertStatus(423);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.editor_tenant_ids', []);
    $this->getJson('/api/connected-website/carolina-barrel/content')->assertStatus(423);
});

test('cross tenant actors versions and stale drafts cannot overwrite connected content', function () {
    $outsider = User::factory()->tenantAdmin()->create(['is_active' => true]);
    $content = $this->service->manifest()['defaults'];
    expect(fn () => $this->service->save($this->site, $content, $outsider, $this->site->draft_site_version_id))->toThrow(HttpException::class);
    $old = $this->site->draft_site_version_id;
    $this->service->save($this->site, $content, $this->actor, $old);
    expect(fn () => $this->service->save($this->site, $content, $this->actor, $old))->toThrow(HttpException::class);
    expect(fn () => $this->service->publish($this->site, $this->actor, $old))->toThrow(HttpException::class);
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    $otherSite = TenantSite::query()->create(['tenant_id' => $other->id, 'subdomain' => 'other', 'status' => 'draft']);
    $otherVersion = $otherSite->siteVersions()->create(['tenant_id' => $other->id, 'version_number' => 1, 'status' => 'draft', 'settings' => ['connected_renderer' => ConnectedWebsiteService::RENDERER, 'connected_content' => $content]]);
    expect(fn () => $this->service->content($this->site, $otherVersion->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('unsafe URLs missing fields and invalid prices are rejected', function () {
    $manifest = $this->service->manifest();
    foreach (['link' => 'javascript:alert(1)', 'image' => '//bad.example/image.jpg', 'money' => '-20'] as $type => $value) {
        $content = $manifest['defaults'];
        $key = array_key_first(array_filter($manifest['fields'], fn ($field) => $field['type'] === $type));
        $content[$key] = $value;
        expect(fn () => $this->service->validateContent($content))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    expect(fn () => $this->service->validateContent([]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('inquiries are tenant owned retry safe and send no messages', function () {
    Mail::fake();
    Notification::fake();
    $data = ['requestId' => (string) Str::uuid(), 'type' => 'quote', 'name' => 'Test visitor', 'email' => 'visitor@example.test', 'notes' => 'Test request', 'productSlug' => 'five-stave-lounge-chair', 'quantity' => 2, 'finish' => 'Discuss finish'];
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', $data)->assertCreated();
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', $data)->assertCreated();
    expect(FormSubmission::query()->count())->toBe(1)->and(FormSubmission::query()->first()->tenant_id)->toBe($this->tenant->id);
    $data['notes'] = 'Different';
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', $data)->assertStatus(409);
    $data['requestId'] = (string) Str::uuid();
    $data['productSlug'] = 'other-tenant-product';
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', $data)->assertUnprocessable();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('workspace overview and products show live editor and block legacy mutations', function () {
    $this->actingAs($this->actor);
    $this->get(route('managed-website.index', ['tenant' => $this->tenant->slug]))->assertOk()->assertSeeText('Edit your live design.');
    $this->get(route('managed-website.products.index', ['tenant' => $this->tenant->slug]))->assertOk()->assertSeeText('Products')->assertSeeText('Five-Stave')->assertDontSeeText('Edit your live design.');
    $this->post(route('managed-website.themes.apply'), ['theme_key' => 'anything'])->assertStatus(409);
    $this->post(route('managed-website.publish'))->assertStatus(409);
    $this->postJson(route('managed-website.products.store'), [])->assertUnprocessable();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->get(route('managed-website.connected.index'))->assertForbidden();
});

test('catalog edits and new products survive page publishing and become browsable request options', function () {
    $this->service->initializeCatalog($this->site, $this->actor);
    $count = \App\Models\WebsiteCollection::query()->count();
    $this->service->initializeCatalog($this->site, $this->actor);
    expect(\App\Models\WebsiteCollection::query()->count())->toBe($count)->toBeGreaterThan(0);
    $catalog = app(\App\Services\ManagedWebsite\WebsiteCatalogService::class);
    $product = $catalog->saveProduct($this->site, null, ['title' => 'New custom bench', 'handle' => 'new-custom-bench', 'description' => 'A bench', 'status' => 'active', 'product_type' => 'quote', 'track_inventory' => false, 'media' => [], 'collection_ids' => [], 'variants' => [['title' => 'Large', 'price' => '425.25', 'is_available' => true]]], $this->actor);
    $existing = WebsiteProduct::query()->where('handle', 'five-stave-lounge-chair')->firstOrFail();
    $existing->update(['title' => 'Edited in Products', 'status' => 'archived']);
    $draft = $this->service->save($this->site, $this->service->manifest()['defaults'], $this->actor, $this->site->draft_site_version_id);
    $this->service->publish($this->site, $this->actor, $draft->id);
    expect($existing->fresh()->title)->toBe('Edited in Products')->and($existing->fresh()->status)->toBe('archived');
    $this->getJson('/api/connected-website/carolina-barrel/content')->assertOk()->assertJsonFragment(['slug' => 'new-custom-bench'])->assertJsonMissing(['slug' => 'five-stave-lounge-chair']);
    $data = ['requestId' => (string) Str::uuid(), 'type' => 'quote', 'name' => 'Visitor', 'email' => 'visitor@example.test', 'notes' => 'Bench request', 'productSlug' => $product->handle, 'variantId' => $product->variants->first()->id, 'quantity' => 1, 'finish' => 'Natural'];
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', $data)->assertCreated();
    expect(data_get(FormSubmission::query()->first()->payload, 'variant_title'))->toBe('Large');
    $data['requestId'] = (string) Str::uuid();
    $data['variantId'] = $existing->variants->first()->id;
    $this->postJson('/api/connected-website/carolina-barrel/inquiries', $data)->assertUnprocessable();
});
