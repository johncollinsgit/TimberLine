<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['logging.default' => 'null', 'managed_website.editor_enabled' => true,
        'managed_website.publishing_enabled' => false, 'managed_website.public_render_enabled' => false]);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional private catalog', 'slug' => 'private-catalog']);
    $this->owner = User::factory()->tenantAdmin()->create(['approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test']);
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->owner);
    $this->product = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Fictional draft linen jacket', 'handle' => 'linen-jacket', 'description' => 'Private cotton lining', 'product_type' => 'physical', 'status' => 'draft',
        'track_inventory' => true, 'media' => ['/images/website-starters/apparel-studio-hero.png']]);
    $this->variant = $this->product->variants()->create(['tenant_id' => $this->tenant->id, 'title' => 'Medium', 'price_cents' => 4250,
        'sku' => 'PRIVATE-JACKET', 'is_available' => true, 'inventory_quantity' => 3]);
    $this->actingAs($this->owner);
});

test('owned shop and product previews use draft settings and permit no purchase or public activation', function (): void {
    $websites = app(ManagedWebsiteService::class);
    $draft = $this->site->draft_site_version_id;
    $published = $this->site->published_site_version_id;
    $shop = $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))
        ->assertOk()->assertSeeText($this->product->title)->assertViewHas('storePreview', true)
        ->assertViewHas('storeSettings', $this->site->draftSiteVersion->settings)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($shop->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $item = $this->get(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))
        ->assertOk()->assertSeeText('Medium')->assertSeeText('$42.50')->assertViewHas('cartEnabled', false)
        ->assertViewHas('checkoutReady', false)->assertViewHas('quoteRequestAllowed', false);
    expect(data_get($item->viewData('storefront'), 'can_cart'))->toBeFalse()
        ->and(data_get($item->viewData('storefront'), 'can_account'))->toBeFalse()
        ->and(data_get($item->viewData('storefront'), 'checkout_ready'))->toBeFalse()
        ->and($item->viewData('variantChoices')->first()['action'])->toBe('')
        ->and($this->site->fresh()->draft_site_version_id)->toBe($draft)
        ->and($this->site->fresh()->published_site_version_id)->toBe($published)
        ->and($this->site->fresh()->public_enabled)->toBeFalse();
    foreach ([$shop, $item] as $preview) {
        $document = new DOMDocument;
        @$document->loadHTML($preview->getContent());
        expect((new DOMXPath($document))->query('//form[translate(@method,"POST","post")="post"]')->length)->toBe(0);
    }
    $this->postJson(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertMethodNotAllowed();
    $this->postJson(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))->assertMethodNotAllowed();
    Http::assertNothingSent();
});

test('private collection filters search and selected variants remain owned and exclude archived records', function (): void {
    $collection = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Private jackets', 'handle' => 'jackets', 'status' => 'draft']);
    $collection->products()->attach($this->product, ['tenant_id' => $this->tenant->id, 'position' => 0]);
    $query = ['tenant' => $this->tenant->slug, 'collection' => $collection->handle, 'q' => 'PRIVATE-JACKET'];
    $this->get(route('managed-website.preview.shop', $query))->assertOk()->assertSeeText($this->product->title);
    $this->get(route('managed-website.preview.shop', [...$query, 'q' => 'not-a-product']))->assertOk()->assertDontSeeText($this->product->title);
    $this->get(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug, 'variant' => $this->variant->id]))->assertOk();
    $this->get(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug, 'variant' => $this->variant->id + 1000]))->assertNotFound();
    $this->product->forceFill(['status' => 'archived'])->save();
    $this->get(route('managed-website.preview.shop', $query))->assertOk()->assertDontSeeText($this->product->title);
    $this->get(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))->assertNotFound();
    Http::assertNothingSent();
});

test('private catalog preview rejects foreign product and tenant selection before exposing draft content', function (): void {
    $other = Tenant::query()->create(['name' => 'Foreign private business', 'slug' => 'foreign-private-business']);
    $foreignSite = app(ManagedWebsiteService::class)->createSite($other, null);
    $foreign = WebsiteProduct::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $foreignSite->id,
        'title' => 'Never reveal this private product', 'handle' => 'private-foreign', 'product_type' => 'physical', 'status' => 'draft']);
    $this->get(route('managed-website.preview.product', ['product' => $foreign, 'tenant' => $this->tenant->slug]))->assertNotFound()->assertDontSee($foreign->title);
    $this->getJson(route('managed-website.preview.shop', ['tenant' => $other->slug]))->assertForbidden()->assertDontSee($foreign->title);
    $this->site->forceFill(['draft_site_version_id' => $foreignSite->draft_site_version_id])->save();
    $this->getJson(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertNotFound();
    $this->getJson(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))->assertNotFound();
    Http::assertNothingSent();
});

test('a corrupt foreign collection membership cannot place a product in the owned preview collection', function (): void {
    $collection = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Owned collection', 'handle' => 'owned-collection', 'status' => 'active']);
    $other = Tenant::query()->create(['name' => 'Other pivot owner', 'slug' => 'other-pivot-owner']);
    $collection->products()->attach($this->product, ['tenant_id' => $other->id, 'position' => 0]);
    $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug, 'collection' => $collection->handle]))
        ->assertOk()->assertDontSeeText($this->product->title);
    $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertOk()->assertSeeText($this->product->title);
    Http::assertNothingSent();
});

test('private Sawyer category filtering uses its draft theme and keeps the category in owned GET navigation', function (): void {
    app(ManagedWebsiteService::class)->applyTheme($this->site, 'sawyer-naturals', $this->owner);
    $this->product->forceFill(['service_details' => ['categories' => ['apparel']]])->save();
    $other = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Fictional draft face wash', 'handle' => 'draft-face-wash', 'product_type' => 'physical', 'status' => 'draft',
        'service_details' => ['categories' => ['face-care']]]);
    $response = $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug, 'category' => 'apparel']))
        ->assertOk()->assertViewHas('category', 'apparel')->assertSeeText($this->product->title)->assertDontSeeText($other->title);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $links = (new DOMXPath($document))->query('//nav[@aria-label="Product categories"]/a');
    expect($links->length)->toBe(8);
    foreach ($links as $link) {
        $url = parse_url($link->getAttribute('href'));
        parse_str($url['query'] ?? '', $query);
        expect($url['path'])->toBe('/website/preview/shop')->and($query['tenant'] ?? null)->toBe($this->tenant->slug);
    }
    $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug, 'category' => 'face-care']))
        ->assertOk()->assertViewHas('category', 'face-care')->assertSeeText($other->title)->assertDontSeeText($this->product->title);
    $this->getJson(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug, 'category' => str_repeat('x', 161)]))->assertUnprocessable();
    Http::assertNothingSent();
});

test('global admin role cannot bypass a viewer or inactive preview membership', function (string $role, bool $active): void {
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => $role, 'membership_active' => $active]);
    $this->getJson(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertForbidden();
    $this->getJson(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))->assertForbidden();
    Http::assertNothingSent();
})->with([['viewer', true], ['admin', false]]);

test('anonymous visitors cannot load private catalog previews', function (): void {
    auth()->forgetGuards();
    $this->flushSession();
    $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertRedirect();
    $this->get(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))->assertRedirect();
    Http::assertNothingSent();
});

test('connected customer renderers cannot be replaced by a native private catalog projection', function (): void {
    $this->tenant->forceFill(['slug' => \App\Services\ManagedWebsite\ConnectedWebsiteService::SLUG])->save();
    $settings = [...$this->site->settings, 'connected_renderer' => \App\Services\ManagedWebsite\ConnectedWebsiteService::RENDERER,
        'connected_content' => ['private_fixture' => 'Retain the connected adapter']];
    $this->site->forceFill(['settings' => $settings])->save();
    $draft = $this->site->draft_site_version_id;
    $this->getJson(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertStatus(409);
    $this->getJson(route('managed-website.preview.product', ['product' => $this->product, 'tenant' => $this->tenant->slug]))->assertStatus(409);
    expect($this->site->fresh()->settings)->toBe($settings)->and($this->site->fresh()->draft_site_version_id)->toBe($draft);
    Http::assertNothingSent();
});

test('active and expired trials may inspect retained catalog without obtaining catalog writes or purchase controls', function (bool $expired): void {
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'apparel']]);
    $this->actingAs($actor)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional preview trial', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    $tenant = $actor->tenants()->firstOrFail();
    $this->actingAs($actor->fresh());
    $this->post(route('managed-website.create', ['tenant' => $tenant->slug]))->assertRedirect();
    $site = \App\Models\TenantSite::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $clock = $tenant->accessProfile->metadata['self_service_trial'];
    if ($expired) {
        $this->travelTo(\Carbon\CarbonImmutable::parse($clock['ends_at']));
    }
    $this->get(route('managed-website.preview.shop', ['tenant' => $tenant->slug]))->assertOk()
        ->assertViewHas('storePreview', true)->assertViewHas('cartEnabled', false);
    $this->postJson(route('managed-website.products.store', ['tenant' => $tenant->slug]), [])->assertStatus(423);
    expect($tenant->accessProfile->fresh()->metadata['self_service_trial'])->toBe($clock)
        ->and($site->fresh()->published_site_version_id)->toBeNull()->and($site->fresh()->public_enabled)->toBeFalse();
    Http::assertNothingSent();
})->with([false, true]);
