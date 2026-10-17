<?php

use App\Models\LandlordProspect;
use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteProduct;
use App\Models\WebsiteShipment;
use App\Services\Retail\DemoRateProvider;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->owner = User::factory()->create(['email' => 'showroom-owner@everbranch.invalid', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    $this->showroom = app(RetailShowroomService::class);
    $this->showroom->seed($this->owner);
    $this->host = parse_url(route('retail.showroom'), PHP_URL_HOST);
    $this->base = 'http://'.$this->host.'/retail/showroom/api/';
    $this->actingAs($this->owner);
});
test('seeding preserves edits and creates exactly twenty isolated drafts and prospects', function () {
    $p = WebsiteProduct::first();
    $p->update(['title' => 'Operator edit']);
    $this->showroom->seed($this->owner);
    expect(Tenant::where('slug', 'like', 'ww-demo-%')->count())->toBe(20)->and(TenantOnboardingBlueprint::where('status', 'draft')->count())->toBe(20)->and(LandlordProspect::where('source', 'woolly-worm-2026')->count())->toBe(20)->and($p->fresh()->title)->toBe('Operator edit');
    expect(TenantSite::where('public_enabled', true)->count())->toBe(0);
    expect($this->showroom->catalog('sawyer-naturals')['products'])->toHaveCount(62);
});
test('a private showroom requires the configured active verified owner', function () {
    $this->get('http://'.$this->host.'/retail/showroom')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    config()->set('retail_showroom.owner_email', '');
    $this->get('http://'.$this->host.'/retail/showroom')->assertForbidden();
    auth()->forgetGuards();
    $this->getJson($this->base.'bootstrap')->assertForbidden();
});
test('catalogs omit private notes research contacts and scores', function () {
    $r = $this->getJson($this->base.'bootstrap')->assertOk();
    expect($r->json('vendors'))->toHaveCount(21);
    expect($r->getContent())->not->toContain('private_notes', 'fit_score', 'requires_merchant_confirmation', 'sample-0@', 'site_review', 'opportunity', 'live_mobile_review');
});
test('packing changes quantities and mixed groups without offering meat shipping', function () {
    $one = $this->showroom->quote('mcneills-pottery', [['product_id' => 'p1', 'variant_id' => 'blue', 'quantity' => 1]], ['zip' => '28604', 'state' => 'NC']);
    $two = $this->showroom->quote('mcneills-pottery', [['product_id' => 'p1', 'variant_id' => 'blue', 'quantity' => 2]], ['zip' => '28604', 'state' => 'NC']);
    expect($one['package_groups'][0]['weight_oz'])->toBe(20)->and($two['package_groups'][0]['weight_oz'])->toBe(40)->and($two['options'][1]['amount_cents'])->toBeGreaterThan($one['options'][1]['amount_cents']);
    $mixed = $this->showroom->quote('highland-croft-candle-company', [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1], ['product_id' => 'p3', 'variant_id' => 'standard', 'quantity' => 1]], ['zip' => '28604', 'state' => 'NC']);
    expect($mixed['package_groups'])->toHaveCount(2);
    $farm = $this->showroom->quote('old-beech-mountain-farms', [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]], ['zip' => '90210', 'state' => 'CA']);
    expect(array_column($farm['options'], 'id'))->toBe(['pickup']);
});
test('Mountain Momma free shipping is strictly over 175 and does not leak', function () {
    $v = $this->showroom->merchant('mountain-momma-organics');
    $provider = new DemoRateProvider;
    foreach ([17400 => false, 17500 => false, 17600 => true] as $subtotal => $free) {
        $q = $provider->quote($v, ['zip' => '28604', 'state' => 'NC', 'subtotal_cents' => $subtotal], [['weight_oz' => 16, 'oversized' => false]], $v['shipping']);
        expect($q['options'][1]['amount_cents'] === 0)->toBe($free);
    }
    $v = $this->showroom->merchant('joy-fudge-company');
    $q = $provider->quote($v, ['zip' => '28604', 'state' => 'NC', 'subtotal_cents' => 20000], [['weight_oz' => 16, 'oversized' => false]], $v['shipping']);
    expect($q['options'][1]['amount_cents'])->toBeGreaterThan(0);
});
test('invalid destinations foreign items sold out pieces and unsupported quantities fail', function () {
    foreach ([['zip' => 'no', 'state' => 'NC'], ['zip' => '00000', 'state' => 'ZZ']] as $d) {
        $this->postJson($this->base.'joy-fudge-company/quote', ['lines' => [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]], 'destination' => $d])->assertUnprocessable();
    }
    foreach ([['product_id' => 'foreign', 'variant_id' => 'standard', 'quantity' => 1], ['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 2], ['product_id' => 'p4', 'variant_id' => 'standard', 'quantity' => 1]] as $line) {
        $this->postJson($this->base.'4-pelicans-woodworking/quote', ['lines' => [$line], 'destination' => ['zip' => '28604', 'state' => 'NC']])->assertUnprocessable();
    }
});
test('Website carts cannot cross merchant scope', function () {
    $lines = [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]];
    $first = $this->showroom->syncCart('joy-fudge-company', $lines, null);
    $second = $this->showroom->syncCart('leather-link-studio', $lines, $first->token);
    expect($second->tenant_id)->not->toBe($first->tenant_id)->and($first->fresh('items')->items)->toHaveCount(1)->and($second->items)->toHaveCount(1);
});
test('sample checkout is idempotent and never creates payments orders shipments or messages', function () {
    $slug = 'joy-fudge-company';
    $customer = WebsiteCustomer::where('tenant_id', $this->showroom->site($slug)->tenant_id)->first();
    $input = ['lines' => [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]], 'destination' => ['zip' => '28604', 'state' => 'NC'], 'service' => 'pickup', 'customer_id' => $customer->id, 'redeem_points' => 100];
    $before = [WebsiteOrder::count(), WebsitePayment::count(), WebsiteShipment::count()];
    $key = 'demo-checkout-request-123456';
    $a = $this->showroom->checkout($slug, $input, $this->owner, $key);
    $b = $this->showroom->checkout($slug, $input, $this->owner, $key);
    expect($a)->toBe($b)->and($a['is_demo'])->toBeTrue()->and($a['reward_credit_cents'])->toBe(500)->and(data_get($customer->fresh()->notes, 'reward_points'))->toBe(57)->and(data_get($customer->fresh()->notes, 'reward_entries'))->toHaveCount(1)->and([WebsiteOrder::count(), WebsitePayment::count(), WebsiteShipment::count()])->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
test('members see their assigned staff workspace but cannot open prospect records or another merchant', function () {
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $site = $this->showroom->site('joy-fudge-company');
    $member->tenants()->attach($site->tenant_id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($member);
    $this->getJson($this->base.'joy-fudge-company/staff')->assertOk()->assertJsonPath('role', 'associate');
    $this->getJson($this->base.'research')->assertForbidden();
    $this->getJson($this->base.'leather-link-studio/staff')->assertForbidden();
    $foreign = WebsiteCustomer::where('tenant_id', $this->showroom->site('leather-link-studio')->tenant_id)->first();
    $this->postJson($this->base.'joy-fudge-company/notes', ['customer_id' => $foreign->id, 'body' => 'No'])->assertNotFound();
});
test('private notes stay out of storefront bootstrap and shared links can be revoked', function () {
    $slug = 'joy-fudge-company';
    $c = WebsiteCustomer::where('tenant_id', $this->showroom->site($slug)->tenant_id)->first();
    $this->postJson($this->base.$slug.'/notes', ['customer_id' => $c->id, 'body' => 'Private sample preference'])->assertOk();
    $this->getJson($this->base.'bootstrap')->assertDontSee('Private sample preference');
    $link = $this->postJson($this->base.$slug.'/share')->assertOk()->json('url');
    auth()->forgetGuards();
    $this->get($link)->assertOk();
    $this->getJson($this->base.'bootstrap?merchant='.$slug)->assertOk()->assertJsonCount(1, 'vendors');
    $this->getJson($this->base.$slug.'/staff')->assertUnauthorized();
    $this->actingAs($this->owner)->deleteJson($this->base.$slug.'/share')->assertOk();
    auth()->forgetGuards();
    $this->get($link)->assertNotFound();
    $this->getJson($this->base.'bootstrap?merchant='.$slug)->assertForbidden();
});

test('existing prospects are linked without duplicating or replacing their history', function () {
    $p = LandlordProspect::where('business_name', 'Joy Fudge Company')->firstOrFail();
    $p->update(['business_name' => 'JOY FUDGE COMPANY', 'source' => 'Existing research', 'notes' => 'Existing relationship']);
    $count = LandlordProspect::count();
    $this->showroom->seed($this->owner);
    expect(LandlordProspect::count())->toBe($count)->and($p->fresh()->source)->toBe('Existing research')->and($p->fresh()->notes)->toBe('Existing relationship');
    $this->getJson($this->base.'research')->assertOk()->assertJsonCount(20, 'prospects');
});

test('a signed-in preview recipient gets only the shared storefront without staff access', function () {
    $slug = 'joy-fudge-company';
    $link = $this->postJson($this->base.$slug.'/share')->assertOk()->json('url');
    $recipient = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actingAs($recipient);
    $this->get($link)->assertOk();
    $this->getJson($this->base.'bootstrap?merchant='.$slug)->assertOk()->assertJsonCount(1, 'vendors')->assertJsonPath('staff', false)->assertJsonPath('owner', false)->assertDontSee('private_notes');
    $this->getJson($this->base.$slug.'/staff')->assertForbidden();
    $this->putJson($this->base.$slug.'/cart', ['lines' => []])->assertForbidden();
    $this->postJson($this->base.$slug.'/checkout', [])->assertForbidden();
    $this->getJson($this->base.'research')->assertForbidden();
    $this->getJson($this->base.'bootstrap?merchant=leather-link-studio')->assertForbidden();
    $this->actingAs($this->owner)->deleteJson($this->base.$slug.'/share')->assertOk();
    $this->actingAs($recipient)->getJson($this->base.'bootstrap?merchant='.$slug)->assertForbidden();
});
test('draft onboarding uses the canonical fresh-customer contract and excludes sample history', function () {
    $draft = TenantOnboardingBlueprint::firstOrFail();
    expect($draft->payload['version'])->toBe(1)->and($draft->payload['tenant_creation_policy'])->toBe('create_fresh_production_tenant')->and($draft->payload['selected_modules'])->toBe([])->and(data_get($draft->payload, 'demo_origin.merchant_activation_approved'))->toBeFalse()->and(data_get($draft->payload, 'demo_origin.review_configuration.catalog'))->toHaveCount(6)->and(data_get($draft->payload, 'demo_origin.excluded'))->toContain('customers', 'orders', 'users', 'rewards');
    $p = $draft->payload;
    $p['desired_outcome_first'] = 'An owner edit';
    $draft->payload = $p;
    $draft->save();
    $this->showroom->seed($this->owner);
    expect($draft->fresh()->payload['desired_outcome_first'])->toBe('An owner edit');
});

test('the private festival route saves walking order without replacing notes or sending outreach', function () {
    $this->putJson($this->base.'joy-fudge-company/prospect', ['stage' => 'Interested', 'walking_order' => 1, 'interest' => 'Try a branded gift box', 'notes' => 'Sample private visit note'])->assertOk();
    $p = LandlordProspect::where('business_name', 'Joy Fudge Company')->firstOrFail();
    expect(data_get($p->source_snapshot, 'festival.walking_order'))->toBe(1)->and($p->notes)->toBe('Sample private visit note');
    $this->showroom->seed($this->owner);
    expect(data_get($p->fresh()->source_snapshot, 'festival.walking_order'))->toBe(1);
    Mail::assertNothingSent();
    Http::assertNothingSent();
    $this->putJson($this->base.'joy-fudge-company/prospect', ['stage' => 'Visit', 'walking_order' => 0])->assertUnprocessable();
});

test('all twenty concepts keep catalogs customers carts and replayed sample checkouts isolated', function () {
    $vendors = $this->showroom->definitions()['vendors'];
    expect($vendors)->toHaveCount(20);
    $tenantIds = [];
    $tokens = [];
    $actual = [WebsiteOrder::count(), WebsitePayment::count(), WebsiteShipment::count()];
    foreach ($vendors as $index => $merchant) {
        $slug = $merchant['slug'];
        $site = $this->showroom->site($slug);
        $tenantIds[] = $site->tenant_id;
        $catalog = $this->showroom->catalog($slug);
        expect($catalog['products'])->toHaveCount(6);
        $product = collect($catalog['products'])->first(fn ($p) => ! $p['quote_only'] && collect($p['variants'])->contains(fn ($v) => $v['stock'] > 0));
        $variant = collect($product['variants'])->first(fn ($v) => $v['stock'] > 0);
        $lines = [['product_id' => $product['id'], 'variant_id' => $variant['id'], 'quantity' => 1]];
        $cart = $this->showroom->syncCart($slug, $lines, $tokens[0] ?? null);
        $tokens[] = $cart->token;
        expect($cart->tenant_id)->toBe($site->tenant_id);
        $customer = WebsiteCustomer::where('tenant_id', $site->tenant_id)->firstOrFail();
        $input = ['lines' => $lines, 'destination' => ['zip' => '28604', 'state' => 'NC'], 'service' => 'pickup', 'customer_id' => $customer->id];
        $key = 'twenty-concepts-replay-'.$index;
        $order = $this->showroom->checkout($slug, $input, $this->owner, $key);
        expect($this->showroom->checkout($slug, $input, $this->owner, $key))->toBe($order)
            ->and($order['is_demo'])->toBeTrue()
            ->and($order['subtotal_cents'])->toBe($variant['price_cents'])
            ->and(data_get($site->fresh()->settings, 'retail_demo.orders'))->toHaveCount(1)
            ->and(data_get($customer->fresh()->notes, 'reward_entries'))->toHaveCount(1);
        if (isset($merchant['category_label'])) {
            expect($catalog['category_label'])->toBe($merchant['category_label'])
                ->and($catalog['intro'])->toBe($merchant['intro'])
                ->and($catalog['shopping_journey'])->toBe($merchant['shopping_journey']);
        }
    }
    expect(array_unique($tenantIds))->toHaveCount(20)
        ->and(array_unique($tokens))->toHaveCount(20)
        ->and([WebsiteOrder::count(), WebsitePayment::count(), WebsiteShipment::count()])->toBe($actual);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('additional website observations stay in private prospect research and survive reseeding', function () {
    $source = json_decode(file_get_contents(resource_path('data/retail-additional-vendor-research.json')), true, 512, JSON_THROW_ON_ERROR)['vendors'];
    expect($source)->toHaveCount(13);
    foreach ($source as $review) {
        $p = LandlordProspect::where('source_snapshot->showroom->slug', $review['slug'])->firstOrFail();
        expect(data_get($p->source_snapshot, 'research.site_review.opportunity'))->toBe($review['site_review']['opportunity']);
        $catalog = $this->showroom->catalog($review['slug']);
        expect($catalog)->not->toHaveKeys(['web_presence', 'concept', 'site_review', 'sources']);
    }
    $p = LandlordProspect::where('source_snapshot->showroom->slug', $source[0]['slug'])->firstOrFail();
    $snapshot = $p->source_snapshot;
    $snapshot['festival']['walking_order'] = 88;
    $snapshot['research']['site_review']['opportunity'] = 'Owner reviewed the next conversation.';
    $p->source_snapshot = $snapshot;
    $p->save();
    $this->showroom->seed($this->owner);
    expect(data_get($p->fresh()->source_snapshot, 'festival.walking_order'))->toBe(88)
        ->and(data_get($p->fresh()->source_snapshot, 'research.site_review.opportunity'))->toBe('Owner reviewed the next conversation.');
    $this->getJson($this->base.'bootstrap')->assertOk()->assertDontSee('Owner reviewed the next conversation.')->assertDontSee('site_review');
});
