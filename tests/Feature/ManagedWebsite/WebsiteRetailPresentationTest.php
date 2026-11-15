<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use App\Services\ManagedWebsite\WebsitePilotService;
use App\Services\ManagedWebsite\WebsiteStorefrontPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SelfServiceWebsitePurchaseFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('local');
    config()->set(['logging.default' => 'null', 'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.editor_tenant_ids' => [], 'managed_website.commerce_enabled' => true,
        'tenant_payments.setup_enabled' => false]);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional retail studio', 'slug' => 'retail-presentation']);
    TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct',
        'metadata' => ['self_service_registration' => true, 'tenant_blueprint' => ['business_template' => 'apparel']]]);
    $this->owner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::fake(fn () => throw new RuntimeException('Retail presentation must never call a provider.'));
    $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    $this->websites = app(ManagedWebsiteService::class);
    $this->site = $this->websites->createSite($this->tenant, $this->owner);
    $this->websites->applyTheme($this->site, 'starter-apparel', $this->owner);
    $this->site->refresh();
    app(WebsitePilotService::class)->saveSetup($this->tenant, $this->site, ['theme_key' => 'starter-apparel',
        'contact_name' => 'Fictional Linen Studio', 'contact_email' => 'fixture@example.test'], $this->owner);
    $this->site->refresh();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

test('a paid retail starter has a complete empty catalog guide without example products prices or stock', function (): void {
    expect($this->site->pages()->count())->toBe(6)
        ->and(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(WebsiteCollection::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(WebsiteOrder::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    expect(collect($home->draftVersion->blocks)->pluck('type'))->toContain('product_grid');
    $this->get(route('managed-website.index', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertSeeText('Your shop')->assertSeeText('Add your first product')->assertSeeText('Manage collections')
        ->assertSeeText('Preview shop')->assertSeeText('Edit menu')->assertSeeText('Payment setup is not available for this workspace yet.');
    $this->get(route('managed-website.editor.preview.site', ['page' => $home, 'tenant' => $this->tenant->slug]))->assertOk()
        ->assertSeeText('Your catalog starts with your products and photos.')->assertSeeText('Set up your catalog')->assertDontSeeText('Everyday pieces');
    expect($this->site->fresh()->public_enabled)->toBeFalse();
});

test('real photos products and collections reach private preview and a deliberate published Shop menu', function (): void {
    $media = $this->postJson('https://app.theeverbranch.com'.route('managed-website.media.store', ['tenant' => $this->tenant->slug], absolute: false), [
        'image' => UploadedFile::fake()->image('fictional-linen.png', 120, 120), 'alt_text' => 'Fictional linen product',
    ])->assertCreated()->json('media');
    $product = app(WebsiteCatalogService::class)->saveProduct($this->site, null, [
        'title' => 'Fictional linen shirt', 'handle' => 'fictional-linen-shirt', 'description' => 'A fictional catalog item.', 'status' => 'active', 'product_type' => 'physical',
        'track_inventory' => true, 'media' => [$media['catalog_url']], 'image_alt' => 'Fictional linen product', 'collection_ids' => [],
        'variants' => [['title' => 'Natural / Small', 'sku' => 'FIXTURE-LINEN-S', 'price' => '25.00', 'inventory_quantity' => 2, 'is_available' => true]],
    ], $this->owner);
    $this->post(route('managed-website.collections.store', ['tenant' => $this->tenant->slug]), [
        'title' => 'Fictional linen', 'handle' => 'fictional-linen', 'status' => 'active', 'description' => '',
        'image_url' => $media['catalog_url'], 'image_alt' => 'Fictional linen cover', 'product_ids' => [$product->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $guide = app(WebsiteStorefrontPresentation::class)->guide($this->site->fresh(), request());
    expect($guide['photos_ready'])->toBeTrue()->and($guide['collections'])->toBe(1)->and($guide['has_shop_link'])->toBeFalse();
    $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertOk()->assertSeeText($product->title)
        ->assertSee(route('managed-website.preview.product', ['product' => $product, 'tenant' => $this->tenant->slug]), false)
        ->assertDontSee('data-variant-cart-form', false);
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->get(route('managed-website.editor', ['page' => $home, 'tenant' => $this->tenant->slug, 'menu' => 1]))->assertOk()->assertSee('data-retail-shop-available="true"', false);
    $draft = $this->site->fresh()->draftSiteVersion;
    $navigation = [...$draft->navigation, ['label' => 'Shop', 'url' => '/shop', 'type' => 'link']];
    $this->putJson(route('managed-website.editor.theme.save', ['tenant' => $this->tenant->slug]), [
        'current_version_id' => $draft->id, 'settings' => $draft->settings, 'navigation' => $navigation,
    ])->assertOk();
    expect($this->site->fresh()->published_site_version_id)->toBeNull();
    $this->post(route('managed-website.publish', ['tenant' => $this->tenant->slug]))->assertRedirect()->assertSessionHasNoErrors();
    $this->get('https://'.$this->tenant->slug.'.theeverbranch.com/')->assertOk()->assertSeeText($product->title)
        ->assertSee('/website-catalog-media/'.$media['id'], false)->assertSeeText('Fictional linen')->assertSee('href="/shop"', false);
    $this->get('https://'.$this->tenant->slug.'.theeverbranch.com/shop')->assertOk()->assertSeeText('Fictional Linen Studio')->assertSeeText($product->title);
    $saved = $this->site->fresh()->draftSiteVersion;
    $this->websites->saveSiteDraft($this->site->fresh(), ['settings' => [...$saved->settings, 'theme_name' => 'Private future brand', 'typography' => 'serif']], $this->owner);
    $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertOk()->assertSeeText('Private future brand')
        ->assertSee('font-family:Georgia, ui-serif, serif', false);
    $this->get('https://'.$this->tenant->slug.'.theeverbranch.com/shop')->assertOk()->assertSeeText('Fictional Linen Studio')->assertDontSeeText('Private future brand');
    $this->get(route('managed-website.index', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertSeeText('Site is live. Publish again to update saved changes.');
    expect($this->site->fresh()->publishedSiteVersion->navigation)->toBe($navigation)
        ->and(TenantSiteMedia::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(WebsiteOrder::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('the presentation preserves published quote-only and custom host gates while preview can inspect owned drafts', function (): void {
    $product = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Owned physical draft', 'handle' => 'physical-draft', 'status' => 'draft', 'product_type' => 'physical']);
    $this->websites->publish($this->site, $this->owner);
    $site = $this->site->fresh();
    $service = app(WebsiteStorefrontPresentation::class);
    $included = Request::create('https://'.$site->subdomain.'.theeverbranch.com/');
    expect($service->capabilities($site, $included)['can_cart'])->toBeTrue()
        ->and($service->cards($site)['products'])->toBe([])
        ->and($service->cards($site, true)['products'][0]['title'])->toBe($product->title);
    $site->publishedSiteVersion->update(['settings' => [...$site->publishedSiteVersion->settings, 'domain_choice' => 'everbranch_subdomain']]);
    $site->refresh();
    expect($service->capabilities($site, $included)['can_cart'])->toBeFalse()
        ->and($service->capabilities($site, $included)['can_account'])->toBeFalse()
        ->and($service->cards($site, true)['products'][0]['title'])->toBe($product->title);
    $custom = Request::create('https://www.fictional.example/');
    $custom->attributes->set('managed_website_relay', true);
    expect($service->capabilities($site, $custom)['custom_host'])->toBeTrue()
        ->and($service->capabilities($site, $custom)['can_cart'])->toBeFalse()
        ->and($service->capabilities($site, $custom)['can_account'])->toBeFalse()
        ->and($service->capabilities($site, $included, true)['can_cart'])->toBeFalse();
});

test('catalog photo cards are bounded and reject foreign records and malformed collection membership', function (): void {
    $foreign = Tenant::query()->create(['name' => 'Foreign retail', 'slug' => 'foreign-retail-card']);
    for ($index = 0; $index < 12; $index++) {
        WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
            'title' => 'Owned item '.$index, 'handle' => 'owned-item-'.$index, 'status' => 'active', 'product_type' => 'physical']);
    }
    WebsiteProduct::query()->create(['tenant_id' => $foreign->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Foreign item hidden', 'handle' => 'foreign-item', 'status' => 'active', 'product_type' => 'physical']);
    $wrongLink = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Malformed membership hidden', 'handle' => 'wrong-link', 'status' => 'active']);
    $wrongLink->products()->attach(WebsiteProduct::query()->where('tenant_id', $this->tenant->id)->first()->id, ['tenant_id' => $foreign->id, 'position' => 0]);
    $cards = app(WebsiteStorefrontPresentation::class)->cards($this->site, true);
    expect($cards['products'])->toHaveCount(8)->and($cards['collections'])->toBe([])
        ->and(collect($cards['products'])->pluck('title'))->not->toContain('Foreign item hidden');
});

test('the existing Sawyer design keeps its private catalog links owned and hides purchase and account controls', function (): void {
    $this->websites->applyTheme($this->site, 'sawyer-naturals', $this->owner);
    $product = app(WebsiteCatalogService::class)->saveProduct($this->site->fresh(), null, [
        'title' => 'Fictional private legacy product', 'handle' => 'private-legacy-product', 'description' => '', 'status' => 'draft', 'product_type' => 'physical',
        'track_inventory' => false, 'media' => [], 'collection_ids' => [],
        'variants' => [['title' => 'Default', 'price' => '5.00', 'is_available' => true]],
    ], $this->owner);
    $product->forceFill(['service_details' => [...($product->service_details ?? []), 'categories' => ['face-care']]])->save();
    $shop = $this->get(route('managed-website.preview.shop', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertSeeText('Private catalog preview. Shopping and payment are disabled.')
        ->assertSee(route('managed-website.preview.product', ['product' => $product, 'tenant' => $this->tenant->slug]), false);
    $item = $this->get(route('managed-website.preview.product', ['product' => $product, 'tenant' => $this->tenant->slug]))->assertOk()
        ->assertSeeText($product->title)->assertDontSee('data-variant-cart-form', false);
    foreach ([$shop, $item] as $response) {
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        expect($xpath->query('//form[translate(@method,"POST","post")="post"]')->length)->toBe(0)
            ->and($xpath->query('//a[contains(@href,"/cart") or contains(@href,"/account")]')->length)->toBe(0);
    }
    $document = new DOMDocument;
    @$document->loadHTML($shop->getContent());
    $faceCare = (new DOMXPath($document))->query('//nav[@aria-label="Shop navigation"]/a[normalize-space(.)="Face care"]')->item(0)->getAttribute('href');
    expect(parse_url($faceCare, PHP_URL_PATH))->toBe('/website/preview/shop');
    parse_str(parse_url($faceCare, PHP_URL_QUERY), $filters);
    expect($filters)->toMatchArray(['tenant' => $this->tenant->slug, 'category' => 'face-care']);
    $this->get($faceCare)->assertOk()->assertSeeText($product->title)->assertViewHas('category', 'face-care');
});

test('collection completion requires a cover and an owned active product through an owned pivot', function (string $case): void {
    $foreign = Tenant::query()->create(['name' => 'Foreign collection source', 'slug' => 'foreign-link-source']);
    $foreignSite = TenantSite::query()->create(['tenant_id' => $foreign->id, 'subdomain' => $foreign->slug, 'status' => 'draft', 'public_enabled' => false]);
    $collection = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'title' => 'Actual collection', 'handle' => 'actual-collection', 'status' => 'active',
        'image_url' => $case === 'missing_cover' ? null : '/images/website-starters/apparel-studio-hero.png']);
    if ($case !== 'empty') {
        $product = WebsiteProduct::query()->create(['tenant_id' => $case === 'foreign' ? $foreign->id : $this->tenant->id,
            'tenant_site_id' => $case === 'wrong_site' ? $foreignSite->id : $this->site->id,
            'title' => 'Linked fixture item', 'handle' => 'linked-fixture-item', 'status' => $case === 'draft' ? 'draft' : 'active', 'product_type' => 'physical']);
        $collection->products()->attach($product, ['tenant_id' => $case === 'wrong_pivot' ? $foreign->id : $this->tenant->id]);
    }
    $response = $this->get(route('managed-website.index', ['tenant' => $this->tenant->slug]))->assertOk();
    $guide = $response->viewData('retailGuide');
    expect($guide['collections_ready'])->toBe($case === 'valid')
        ->and($guide['unlinked_collections'])->toBe(in_array($case, ['valid', 'missing_cover'], true) ? 0 : 1);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $status = (new DOMXPath($document))->query('//section[@id="retail-setup"]//article[.//h3[contains(normalize-space(.),"Collections")]]//h3/span')->item(0);
    expect($status->getAttribute('aria-label'))->toBe($case === 'valid' ? 'Done' : 'In progress');
    if (! in_array($case, ['valid', 'missing_cover'], true)) {
        $response->assertSeeText('1 need active products');
    }
})->with(['empty', 'draft', 'foreign', 'wrong_site', 'wrong_pivot', 'missing_cover', 'valid']);

test('published native and Sawyer child pages retain real host capabilities without exposing private purchase actions', function (string $theme): void {
    config()->set(['managed_website.custom_domains_enabled' => true, 'managed_website.custom_domain_activation_enabled' => true]);
    $this->websites->applyTheme($this->site, $theme, $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $site = $this->site->fresh();
    $countActions = static function ($response): int {
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());

        return (new DOMXPath($document))->query('//a[contains(@href,"/cart") or contains(@href,"/account")]')->length;
    };
    $ordinary = $this->get('https://'.$site->subdomain.'.theeverbranch.com/about')->assertOk();
    expect($countActions($ordinary))->toBeGreaterThan(0);
    TenantSiteDomain::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $site->id,
        'hostname' => 'www.fictional-retail.example', 'status' => 'active', 'is_primary' => true, 'verification_token' => 'fictional-proof']);
    $custom = $this->get('https://www.fictional-retail.example/about')->assertOk();
    expect($countActions($custom))->toBe(0);
    $site->refresh();
    // Seed a trusted legacy draft flag, then use the ordinary menu-save and publish paths.
    $site->draftSiteVersion->update(['settings' => [...$site->draftSiteVersion->settings, 'domain_choice' => 'everbranch_subdomain']]);
    $this->websites->saveSiteDraft($site->fresh(), ['settings' => ['domain_choice' => null]], $this->owner);
    $this->websites->publish($site->fresh(), $this->owner);
    $quoted = $this->get('https://'.$site->subdomain.'.theeverbranch.com/about')->assertOk();
    expect($countActions($quoted))->toBe(0);
    $about = $site->pages()->where('slug', 'about')->firstOrFail();
    $private = $this->get(route('managed-website.editor.preview.site', ['page' => $about, 'tenant' => $this->tenant->slug]))->assertOk();
    expect($countActions($private))->toBe(0);
})->with(['starter-apparel', 'sawyer-naturals']);

test('a real active or expired retail trial offers activation and saved preview without denied catalog actions', function (bool $expired): void {
    config()->set(['self_service.trial_enabled' => true]);
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'apparel']]);
    $this->flushSession();
    $this->actingAs($actor)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional retail trial', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    $tenant = $actor->tenants()->firstOrFail();
    $this->actingAs($actor->fresh())->withSession(['tenant_id' => $tenant->id]);
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $actor);
    $websites->applyTheme($site, 'starter-apparel', $actor);
    $clock = $tenant->accessProfile->metadata['self_service_trial'];
    if ($expired) {
        $this->travelTo(\Carbon\CarbonImmutable::parse($clock['ends_at']));
    }
    $response = $this->get(route('managed-website.index', ['tenant' => $tenant->slug]))->assertOk()->assertSeeText('Your shop')
        ->assertSeeText('Activate plan')->assertSeeText('Preview shop')
        ->assertSee(route('self-service.purchase', ['tenant' => $tenant->slug]).'#activate-plan', false);
    expect($response->viewData('retailGuide')['can_manage_catalog'])->toBeFalse();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    expect((new DOMXPath($document))->query('//a[contains(@href,"/website/products") or contains(@href,"/website/collections")]')->length)->toBe(0);
    $this->get(route('managed-website.preview.shop', ['tenant' => $tenant->slug]))->assertOk();
    expect($tenant->fresh()->accessProfile->metadata['self_service_trial'])->toBe($clock);
})->with([false, true]);

test('ordinary settings saves preserve a trusted existing quote-only restriction through publication', function (string $source): void {
    $site = $this->site->fresh();
    if ($source === 'published') {
        $this->websites->publish($site, $this->owner);
        $site->refresh();
        $site->publishedSiteVersion->update(['settings' => [...$site->publishedSiteVersion->settings, 'domain_choice' => 'everbranch_subdomain']]);
    } elseif ($source === 'draft') {
        $site->draftSiteVersion->update(['settings' => [...$site->draftSiteVersion->settings, 'domain_choice' => 'everbranch_subdomain']]);
    } else {
        $site->update(['settings' => [...$site->settings, 'domain_choice' => 'everbranch_subdomain']]);
    }
    $product = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $site->id,
        'title' => 'Fictional retained physical item', 'handle' => 'retained-physical', 'status' => 'active', 'product_type' => 'physical', 'track_inventory' => false]);
    $variant = $product->variants()->create(['tenant_id' => $this->tenant->id, 'title' => 'Default', 'price_cents' => 2500, 'is_available' => true]);
    foreach ([null, 'custom_domain'] as $replacement) {
        $draft = $site->fresh()->draftSiteVersion;
        $this->putJson(route('managed-website.editor.theme.save', ['tenant' => $this->tenant->slug]), [
            'current_version_id' => $draft->id, 'settings' => [...$draft->settings, 'domain_choice' => $replacement], 'navigation' => $draft->navigation,
        ])->assertOk();
        expect($site->fresh()->draftSiteVersion->settings['domain_choice'])->toBe('everbranch_subdomain');
    }
    $this->post(route('managed-website.publish', ['tenant' => $this->tenant->slug]))->assertRedirect()->assertSessionHasNoErrors();
    expect($site->fresh()->publishedSiteVersion->settings['domain_choice'])->toBe('everbranch_subdomain');
    $origin = 'https://'.$site->subdomain.'.theeverbranch.com';
    $this->get($origin.'/products/'.$product->handle)->assertNotFound();
    $this->postJson($origin.'/cart/items/'.$variant->id, ['product_id' => $product->id, 'quantity' => 1])->assertNotFound();
    expect(\App\Models\WebsiteCart::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
})->with(['draft', 'published', 'compatibility']);

test('a neutral retail starter cannot acquire a quote-only policy flag from client settings', function (): void {
    $draft = $this->site->fresh()->draftSiteVersion;
    $this->putJson(route('managed-website.editor.theme.save', ['tenant' => $this->tenant->slug]), [
        'current_version_id' => $draft->id, 'settings' => [...$draft->settings, 'domain_choice' => 'everbranch_subdomain'], 'navigation' => $draft->navigation,
    ])->assertOk();
    expect($this->site->fresh()->draftSiteVersion->settings)->not->toHaveKey('domain_choice');
    $this->post(route('managed-website.publish', ['tenant' => $this->tenant->slug]))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->site->fresh()->publishedSiteVersion->settings)->not->toHaveKey('domain_choice');
});
