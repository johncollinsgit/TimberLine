<?php

use App\Models\LandlordOperatorAction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineScentSplit;
use App\Models\Scent;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Services\Marketing\CustomerBehaviorProfile;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerOutreachExplanation;
use App\Services\Marketing\CustomerOutreachPolicy;
use App\Services\Marketing\CustomerPreferenceService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Fictional Safety Shop', 'slug' => 'safety-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'email' => 'safety@example.invalid',
        'normalized_email' => 'safety@example.invalid', 'accepts_email_marketing' => false, 'notes' => 'Private staff note never for shoppers']);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test']);
});

function safetyCustomerOrder($test, array $line = [], array $evidence = [], ?MarketingProfile $profile = null): array
{
    $order = Order::factory()->create(['tenant_id' => $test->tenant->id, 'shopify_order_id' => random_int(1000000, 9999999), 'shopify_store_key' => 'safety-fixture', 'attribution_meta' => []]);
    $order->update(['attribution_meta' => ['customer_commerce' => array_replace(['tenant_id' => $test->tenant->id, 'shopify_order_id' => (string) $order->shopify_order_id,
        'store_key' => 'safety-fixture', 'financial_status' => 'paid', 'source_name' => 'web', 'total_price' => '50.00', 'refund_total' => '0.00', 'currency' => 'USD'], $evidence)]]);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => ($profile ?? $test->profile)->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);
    $item = OrderLine::create(array_replace(['order_id' => $order->id, 'raw_title' => 'Fictional candle', 'shopify_product_id' => random_int(10000, 99999), 'quantity' => 1, 'ordered_qty' => 1], $line));

    return [$order, $item];
}

function safetyDraftInput(array $overrides = []): array
{
    return array_replace(['request_id' => (string) Str::uuid(), 'channel' => 'email', 'subject' => 'A note for {{first_name}}', 'body' => 'Hello {{first_name}}.', 'heading' => '', 'button_label' => '', 'button_url' => ''], $overrides);
}

test('customer behavior honors complete scent splits without counting the parent scent again', function () {
    $parent = Scent::create(['name' => 'Fictional parent scent']);
    $cedar = Scent::create(['name' => 'Fictional cedar']);
    $pine = Scent::create(['name' => 'Fictional pine']);
    [, $line] = safetyCustomerOrder($this, ['scent_id' => $parent->id, 'scent_name' => $parent->name, 'quantity' => 6, 'ordered_qty' => 6]);
    OrderLineScentSplit::create(['order_line_id' => $line->id, 'scent_id' => $cedar->id, 'raw_scent_name' => $cedar->name, 'quantity' => 4, 'allocation_type' => 'manual', 'created_by' => $this->actor->id]);
    OrderLineScentSplit::create(['order_line_id' => $line->id, 'scent_id' => $pine->id, 'raw_scent_name' => $pine->name, 'quantity' => 2, 'allocation_type' => 'manual', 'created_by' => $this->actor->id]);
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect(array_column($snapshot['behavior']['most_purchased_scents'], 'name'))->toBe([$cedar->name, $pine->name])
        ->and(array_sum(array_column($snapshot['behavior']['most_purchased_scents'], 'units')))->toBe(6)
        ->and(array_sum(array_column($snapshot['behavior']['most_purchased_products'], 'units')))->toBe(6);
});

test('incomplete split allocations never fall back to an apparently certain parent scent', function () {
    $parent = Scent::create(['name' => 'Fictional parent']);
    $split = Scent::create(['name' => 'Fictional split']);
    [, $line] = safetyCustomerOrder($this, ['scent_id' => $parent->id, 'scent_name' => $parent->name, 'quantity' => 6, 'ordered_qty' => 6]);
    OrderLineScentSplit::create(['order_line_id' => $line->id, 'scent_id' => $split->id, 'raw_scent_name' => $split->name, 'quantity' => 2, 'allocation_type' => 'manual']);
    $behavior = app(CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id)['behavior'];
    expect($behavior['most_purchased_scents'])->toBeEmpty()->and($behavior['partial'])->toBeTrue()->and($behavior['units_without_reviewed_scent'])->toBeGreaterThanOrEqual(6);
});

test('expanded bundle components without stable product identity retain scents but disclose incomplete product grouping', function () {
    $scent = Scent::create(['name' => 'Fictional selected component']);
    foreach ([2, 3] as $qty) {
        safetyCustomerOrder($this, ['shopify_product_id' => null, 'shopify_variant_id' => null, 'scent_id' => $scent->id, 'scent_name' => $scent->name, 'quantity' => $qty, 'ordered_qty' => $qty]);
    }
    $behavior = app(CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id)['behavior'];
    expect($behavior['most_purchased_scents'][0]['units'])->toBe(5)->and($behavior['most_purchased_scents'][0]['purchase_count'])->toBe(2)
        ->and($behavior['partial'])->toBeTrue()->and($behavior['most_purchased_products'])->toBeEmpty();
});

test('same-name products with different recorded identities never merge by name', function () {
    safetyCustomerOrder($this, ['raw_title' => 'Same display title', 'shopify_product_id' => 1001, 'quantity' => 2, 'ordered_qty' => 2]);
    safetyCustomerOrder($this, ['raw_title' => 'Same display title', 'shopify_product_id' => 1002, 'quantity' => 3, 'ordered_qty' => 3]);
    $rows = app(CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id)['behavior']['most_purchased_products'];
    expect($rows)->toHaveCount(2)->and(array_column($rows, 'units'))->toBe([3, 2])->and($rows[0]['key'])->not->toBe($rows[1]['key']);
});

test('partial and unknown refund evidence excludes every affected ranking without erasing real purchases', function () {
    $scent = Scent::create(['name' => 'Fictional refund scent']);
    foreach ([['paid', null], ['partially_refunded', '1.00'], ['refunded', '50.00']] as [$state, $refund]) {
        safetyCustomerOrder($this, ['scent_id' => $scent->id, 'scent_name' => $scent->name, 'quantity' => 7], ['financial_status' => $state, 'refund_total' => $refund]);
    }
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect($snapshot['confirmed_purchase_count'])->toBe(3)->and($snapshot['behavior']['excluded_refunded_or_unknown_refund_purchases'])->toBe(3)
        ->and($snapshot['behavior']['most_purchased_scents'])->toBeEmpty()->and($snapshot['behavior']['most_purchased_products'])->toBeEmpty();
});

test('a model captured before a completed merge resolves to current canonical history', function () {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Former alias']);
    $stale = MarketingProfile::findOrFail($alias->id);
    safetyCustomerOrder($this);
    $alias->update(['merged_into_profile_id' => $this->profile->id, 'merged_at' => now(), 'archived_at' => now()]);
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($stale, $this->tenant->id);
    expect($snapshot['customer_id'])->toBe($this->profile->id)->and($snapshot['confirmed_purchase_count'])->toBe(1);
    $foreign = Tenant::create(['name' => 'Fictional foreign', 'slug' => 'foreign-safety']);
    $other = MarketingProfile::create(['tenant_id' => $foreign->id]);
    $alias->update(['merged_into_profile_id' => $other->id]);
    expect(fn () => app(CustomerCommerceSnapshotService::class)->forProfile($stale, $this->tenant->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('preview rereads current declared choices and contact name rather than captured profile values', function () {
    $stale = MarketingProfile::findOrFail($this->profile->id);
    $preferences = app(CustomerPreferenceService::class);
    $version = $preferences->forStaff($this->profile, $this->tenant->id)['version'];
    $preferences->updateForStaff($this->profile, $this->tenant->id, $this->actor, ['request_id' => (string) Str::uuid(), 'expected_version' => $version,
        'customer_confirmed' => true, 'changes' => ['first_name' => 'Corrected Fictional', 'favorite_scents' => ['Declared Cedar']]]);
    $preview = app(CustomerOutreachDraft::class)->preview($this->tenant, $stale, safetyDraftInput(['body' => 'Hello {{first_name}}. Your declared favorites: {{favorite_scents}}.']));
    expect($preview['body'])->toBe('Hello Corrected Fictional. Your declared favorites: Declared Cedar.')
        ->and($preview['explanation']['preparation_decision']['preferences']['version'])->toBe($preferences->forStaff($this->profile, $this->tenant->id)['version']);
});

test('customer snapshot and shared rendering escape merchant labels without exposing staff settings', function () {
    $badTitle = '<img src=x onerror=privateAttack()>';
    safetyCustomerOrder($this, ['raw_title' => $badTitle]);
    $customer = WebsiteCustomer::create(['tenant_id' => $this->tenant->id, 'email' => $this->profile->email, 'status' => 'active']);
    $service = app(CustomerCommerceSnapshotService::class);
    expect($service->forWebsiteCustomer($customer, $this->tenant->id))->toBeNull();
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => 'website_customer', 'source_id' => (string) $customer->id]);
    $snapshot = $service->forWebsiteCustomer($customer, $this->tenant->id);
    expect(json_encode($snapshot))->not->toContain('Private staff note', 'accepts_email_marketing', 'recorded_choices', 'preparation_audit', 'message_body');
    $html = Blade::render('<x-customer-commerce :snapshot="$snapshot" rewards-label="Rewards" />', ['snapshot' => $snapshot]);
    expect($html)->toContain('&lt;img src=x onerror=privateAttack()&gt;')->not->toContain($badTitle, 'Private staff note');
});

test('draft creation failure rolls back its campaign recipient and audit and a safe retry stays unsent', function () {
    $service = app(CustomerOutreachDraft::class);
    $input = safetyDraftInput();
    $original = app(CustomerOutreachExplanation::class);
    $explanation = Mockery::mock(CustomerOutreachExplanation::class, [app(CustomerOutreachPolicy::class)])->makePartial();
    $explanation->shouldReceive('recordPreparation')->once()->andReturnUsing(function (...$arguments) use ($original) {
        $original->recordPreparation(...$arguments);
        throw new RuntimeException('Fictional journal failure');
    });
    app()->instance(CustomerOutreachExplanation::class, $explanation);
    expect(fn () => $service->save($this->tenant, $this->actor, $this->profile, $input))->toThrow(RuntimeException::class, 'Fictional journal failure');
    expect(MarketingCampaign::forTenantId($this->tenant->id)->where('source_label', 'customer_profile')->count())->toBe(0)
        ->and(MarketingCampaignRecipient::count())->toBe(0)
        ->and(LandlordOperatorAction::forTenantId($this->tenant->id)->where('action_type', 'customer_outreach_prepared')->count())->toBe(0);
    app()->instance(CustomerOutreachExplanation::class, $original);
    $first = $service->save($this->tenant, $this->actor, $this->profile, $input);
    $again = $service->save($this->tenant, $this->actor, $this->profile, $input);
    expect($again->id)->toBe($first->id)->and($first->status)->toBe('draft')->and($first->recipients()->count())->toBe(1)
        ->and(LandlordOperatorAction::forTenantId($this->tenant->id)->where('action_type', 'customer_outreach_prepared')->count())->toBe(1)
        ->and(MarketingEmailDelivery::count())->toBe(0)->and(MarketingMessageDelivery::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0)
        ->and($this->profile->fresh()->accepts_email_marketing)->toBeFalse();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('saved draft content becomes inadmissible after preference edits or content tampering', function () {
    $service = app(CustomerOutreachDraft::class);
    $campaign = $service->save($this->tenant, $this->actor, $this->profile, safetyDraftInput());
    $preferences = app(CustomerPreferenceService::class);
    $preferences->updateForStaff($this->profile, $this->tenant->id, $this->actor, ['request_id' => (string) Str::uuid(), 'expected_version' => $preferences->forStaff($this->profile, $this->tenant->id)['version'],
        'customer_confirmed' => true, 'changes' => ['favorite_scents' => ['New Declared Pine']]]);
    $decision = app(CustomerOutreachExplanation::class)->dispatchDecision($campaign, $this->profile->fresh());
    expect($decision['allowed'])->toBeFalse()->and($decision['reason_codes'])->toContain('outreach_prepared_preferences_changed');
    $campaign->message_html .= '<p>Unreviewed change</p>';
    expect($service->emailContent($campaign, $this->profile, $campaign->message_body))->toBeNull();
    expect(MarketingEmailDelivery::count())->toBe(0)->and(MarketingMessageDelivery::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('anonymous rewards lookup cannot acquire purchase rankings or private preference settings', function () {
    $this->profile->update(['phone' => '8645550101', 'normalized_phone' => '8645550101']);
    \App\Models\ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail', 'shop_domain' => 'safety-rewards.myshopify.com', 'access_token' => 'fixture-only', 'installed_at' => now()]);
    [$order] = safetyCustomerOrder($this, ['raw_title' => 'PRIVATE-PURCHASE-ITEM']);
    $order->update(['order_number' => 'PRIVATE-PURCHASE-NUMBER']);
    $preferences = app(CustomerPreferenceService::class);
    $preferences->updateForStaff($this->profile, $this->tenant->id, $this->actor, ['request_id' => (string) Str::uuid(), 'expected_version' => $preferences->forStaff($this->profile, $this->tenant->id)['version'],
        'customer_confirmed' => true, 'changes' => ['favorite_scents' => ['PRIVATE-DECLARED-SCENT']]]);
    $commerce = Mockery::mock(CustomerCommerceSnapshotService::class);
    $commerce->shouldNotReceive('forProfile');
    $commerce->shouldNotReceive('forWebsiteCustomer');
    app()->instance(CustomerCommerceSnapshotService::class, $commerce);
    $private = Mockery::mock(CustomerPreferenceService::class);
    $private->shouldNotReceive('forCustomer');
    $private->shouldNotReceive('forStaff');
    $private->shouldNotReceive('forWebsiteCustomer');
    app()->instance(CustomerPreferenceService::class, $private);
    $this->get(route('marketing.public.account-rewards', ['email' => $this->profile->email, 'phone' => $this->profile->phone,
        'store_key' => 'retail', 'profile' => $this->profile->id, 'include_preferences' => true, 'include_commerce' => true]))
        ->assertOk()->assertDontSeeText('PRIVATE-PURCHASE-ITEM')->assertDontSeeText('PRIVATE-PURCHASE-NUMBER')
        ->assertDontSeeText('PRIVATE-DECLARED-SCENT')->assertDontSeeText('Private staff note')->assertDontSeeText('Customer purchase profile');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('POS catalog namespaces keep identical item IDs distinct and unknown catalog authority withheld', function () {
    $base = ['source' => 'pos_sale', 'confirmed' => true, 'channel' => 'in_person', 'refunded_minor' => 0, 'status' => 'paid', 'occurred_at' => now()->toIso8601String()];
    $purchases = [];
    foreach ([['stock', 2], ['pos:fixture-runtime', 3], ['pos:unknown', 5]] as $i => [$namespace, $quantity]) {
        $purchases[] = [...$base, 'id' => 'pos_sale:namespace-'.$i, 'source_id' => 'namespace-'.$i, 'catalog_namespace' => $namespace,
            'items' => [['item_id' => str_repeat('a', 32), 'title' => 'Same item label', 'quantity' => $quantity]]];
    }
    $behavior = app(CustomerBehaviorProfile::class)->build($this->tenant->id, $purchases, false, [])['profile'];
    expect($behavior['most_purchased_products'])->toHaveCount(2)->and(array_column($behavior['most_purchased_products'], 'units'))->toBe([3, 2])
        ->and($behavior['most_purchased_products'][0]['key'])->not->toBe($behavior['most_purchased_products'][1]['key'])
        ->and($behavior['units_without_product_identity'])->toBe(5)->and($behavior['partial'])->toBeTrue();
});
