<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\CustomerBehaviorProfile;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerOutreachDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional shop', 'slug' => 'profile-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'ada@example.invalid', 'normalized_email' => 'ada@example.invalid', 'accepts_email_marketing' => false]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test']);
});

function profileConfirmedOrder($test, Scent $scent, int $quantity, string $state = 'paid'): Order
{
    $order = Order::factory()->create(['tenant_id' => $test->tenant->id, 'shopify_order_id' => random_int(100000, 999999), 'shopify_store_key' => 'profile-fixture', 'attribution_meta' => []]);
    $order->update(['attribution_meta' => ['customer_commerce' => ['tenant_id' => $test->tenant->id, 'shopify_order_id' => (string) $order->shopify_order_id, 'store_key' => 'profile-fixture', 'financial_status' => $state, 'source_name' => 'web', 'total_price' => '30.00', 'refund_total' => $state === 'refunded' ? '30.00' : '0.00', 'currency' => 'USD']]]);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->profile->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);
    OrderLine::create(['order_id' => $order->id, 'scent_id' => $scent->id, 'scent_name' => $scent->name, 'raw_title' => $scent->name.' candle', 'shopify_product_id' => $scent->id + 100, 'quantity' => $quantity, 'ordered_qty' => $quantity]);

    return $order;
}

test('shared customer behavior ranks recorded scents across confirmed orders and excludes returns, unverified and foreign records', function () {
    $pine = Scent::create(['name' => 'Fictional pine']);
    $lavender = Scent::create(['name' => 'Fictional lavender']);
    profileConfirmedOrder($this, $pine, 2);
    profileConfirmedOrder($this, $pine, 3);
    profileConfirmedOrder($this, $lavender, 1);
    profileConfirmedOrder($this, $lavender, 99, 'refunded');
    profileConfirmedOrder($this, $lavender, 99, 'pending');
    $foreign = Tenant::create(['name' => 'Other', 'slug' => 'other-profile']);
    $order = Order::factory()->create(['tenant_id' => $foreign->id]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect($snapshot['behavior']['most_purchased_scents'][0])->toBe(['key' => 'scent:'.$pine->id, 'name' => 'Fictional pine', 'units' => 5, 'purchase_count' => 2])
        ->and($snapshot['behavior']['most_purchased_scents'][1]['units'])->toBe(1)->and($snapshot['behavior']['channels']['online'])->toBe(4)
        ->and($snapshot['behavior']['excluded_refunded_or_unknown_refund_purchases'])->toBe(1)->and($snapshot['behavior']['partial'])->toBeTrue();
    expect(collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toBeEmpty();
    DB::disableQueryLog();
    Http::assertNothingSent();
});

test('bundle component quantities are counted once and product names never infer a scent', function () {
    $purchase = ['id' => 'pos_sale:one', 'source' => 'pos_sale', 'source_id' => 'one', 'catalog_namespace' => 'stock', 'confirmed' => true, 'channel' => 'in_person', 'refunded_minor' => 0, 'status' => 'paid', 'occurred_at' => now()->toIso8601String(),
        'items' => [['title' => 'Three candle bundle', 'quantity' => 2, 'components' => [['item_id' => str_repeat('a', 32), 'title' => 'Pine candle', 'quantity' => 4], ['item_id' => str_repeat('b', 32), 'title' => 'Lavender candle', 'quantity' => 2]]]]];
    $result = app(CustomerBehaviorProfile::class)->build($this->tenant->id, [$purchase], false, []);
    expect(array_sum(array_column($result['profile']['most_purchased_products'], 'units')))->toBe(6)
        ->and($result['profile']['most_purchased_scents'])->toBeEmpty()->and($result['profile']['units_without_reviewed_scent'])->toBe(6);
});

test('personalized formatted drafts replay once, stay unsent and preserve channel consent', function () {
    $pine = Scent::create(['name' => 'Fictional pine']);
    profileConfirmedOrder($this, $pine, 2);
    $service = app(CustomerOutreachDraft::class);
    $input = ['request_id' => (string) Str::uuid(), 'channel' => 'email', 'subject' => 'Hello {{first_name}}', 'body' => 'Your most purchased scent is {{most_purchased_scent}}.', 'heading' => 'A note for {{first_name}}', 'button_label' => 'Visit {{company_name}}', 'button_url' => 'https://example.invalid/shop'];
    $first = $service->save($this->tenant, $this->actor, $this->profile, $input);
    $again = $service->save($this->tenant, $this->actor, $this->profile, $input);
    expect($again->id)->toBe($first->id)->and(MarketingCampaign::where('source_label', 'customer_profile')->count())->toBe(1)
        ->and($first->status)->toBe('draft')->and($first->recipients()->first()->status)->toBe('skipped')
        ->and($first->message_html)->toContain('A note for Ada', 'https://example.invalid/shop')->and($first->message_body)->toBe('Your most purchased scent is Fictional pine.')
        ->and($this->profile->fresh()->accepts_email_marketing)->toBeFalse();
    expect($service->emailContent($first, $this->profile, $first->message_body)['subject'])->toBe('Hello Ada')
        ->and($service->emailContent($first, $this->profile, 'Changed body'))->toBeNull();
    $input['body'] = 'Different original request.';
    expect(fn () => $service->save($this->tenant, $this->actor, $this->profile, $input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('profile variables are escaped in email and missing values require review', function () {
    $this->profile->update(['first_name' => '<script>private()</script>']);
    $service = app(CustomerOutreachDraft::class);
    $input = ['channel' => 'email', 'subject' => 'A note', 'body' => 'Hi {{first_name}} {{most_purchased_scent}}', 'heading' => '{{first_name}}'];
    $preview = $service->preview($this->tenant, $this->profile, $input);
    expect($preview['html'])->not->toContain('<script>private()')->and($preview['missing_variables'])->toContain('most_purchased_scent');
    $input['request_id'] = (string) Str::uuid();
    expect(fn () => $service->save($this->tenant, $this->actor, $this->profile, $input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(MarketingCampaign::forTenantId($this->tenant->id)->where('source_label', 'customer_profile')->count())->toBe(0);
});

test('the main workspace searches and displays the same profile with role and tenant isolation', function () {
    $this->actingAs($this->actor)->get('/workspace/customers?tenant='.$this->tenant->slug.'&query=Ada')->assertOk()->assertSeeText('Ada Buyer');
    $url = '/workspace/customers/'.$this->profile->id.'?tenant='.$this->tenant->slug;
    $this->get($url)->assertOk()->assertSeeText('Most purchased scents')->assertSeeText('Personalized email or text');
    $other = Tenant::create(['name' => 'Other profile tenant', 'slug' => 'other-private-profile']);
    $foreign = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Private foreign profile']);
    $this->get('/workspace/customers/'.$foreign->id.'?tenant='.$this->tenant->slug)->assertNotFound();
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($member)->get($url)->assertOk()->assertDontSeeText('Personalized email or text');
    $this->post('/workspace/customers/'.$this->profile->id.'/outreach?tenant='.$this->tenant->slug, ['request_id' => (string) Str::uuid(), 'channel' => 'email', 'body' => 'Test'])->assertForbidden();
});

test('personal draft preparation requires current verified tenant ownership even for platform readers', function () {
    $platformReader = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    $url = route('customers.profile', ['profile' => $this->profile->id, 'tenant' => $this->tenant->slug]);
    $this->actingAs($platformReader)->get($url)->assertForbidden();
    $platformReader->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($platformReader)->get($url)->assertOk()->assertDontSeeText('Personalized email or text');
    expect(fn () => app(CustomerOutreachDraft::class)->authorize($this->tenant, $platformReader, $this->profile))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $staleActor = $this->actor;
    User::whereKey($staleActor->id)->update(['is_active' => false]);
    expect(fn () => app(CustomerOutreachDraft::class)->authorize($this->tenant, $staleActor, $this->profile))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});
