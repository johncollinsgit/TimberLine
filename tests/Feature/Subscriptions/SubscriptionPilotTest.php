<?php

use App\Models\ShopifyStore;
use App\Models\SubscriptionPilotEvent;
use App\Models\SubscriptionPilotMember;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionPilotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com']);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional retail', 'slug' => 'pilot-retail']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['is_active' => true, 'role' => 'manager', 'requested_via' => 'customer_production']);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $this->store = ShopifyStore::query()->create(['tenant_id' => $this->tenant->id, 'store_key' => 'pilot', 'shop_domain' => 'fictional-pilot.myshopify.com', 'access_token' => 'fictional-not-used']);
    $this->input = ['shopify_store_id' => $this->store->id, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456',
        'name' => 'Fictional member', 'email' => 'fictional@example.test', 'target_date' => '2026-11-02', 'timezone' => 'America/New_York',
        'currency' => 'USD', 'expected_amount_cents' => 2500, 'source_url' => 'https://fictional.admin.rechargeapps.com/merchant/customers/123',
        'observed_at' => '2026-10-27T10:00:00-04:00', 'lines' => [['subscription_id' => '789', 'variant_id' => '999', 'label' => 'Monthly candle',
            'quantity' => 1, 'unit_price_cents' => 2500, 'billing_months' => 1, 'delivery_months' => 1, 'prepaid' => false]]];
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(18, 0));
    $this->service = app(SubscriptionPilotService::class);
    $this->actingAs($this->actor);
});

afterEach(function () {
    if (! ($this->providerReadExpected ?? false)) {
        Http::assertNothingSent();
    } Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

function pilotObservation(string $provider = 'recharge', string $kind = 'payment', string $reference = '1001'): array
{
    return ['provider' => $provider, 'kind' => $kind, 'provider_reference' => $reference, 'status' => 'success', 'currency' => 'USD',
        'amount_cents' => 2500, 'cycle_date' => '2026-11-02', 'occurred_at' => '2026-11-02T09:00:00-05:00',
        'observed_at' => '2026-11-02T09:05:00-05:00', 'source_url' => 'https://fictional.admin.rechargeapps.com/merchant/customers/123'];
}

test('preparation is encrypted idempotent and leaves Recharge primary without adopting customers', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $again = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    expect($again->id)->toBe($member->id)->and($member->billing_owner)->toBe('recharge')->and($member->status)->toBe('prepared')
        ->and($member->details['candle_cash_allowed'])->toBeFalse()->and($member->details['source_billing_stopped'])->toBeFalse()
        ->and(DB::table('subscription_pilot_members')->value('details'))->not->toContain('fictional@example.test')
        ->and(DB::table('subscription_contracts')->count())->toBe(0)->and(DB::table('marketing_profiles')->count())->toBe(0);
    $different = $this->input;
    $different['expected_amount_cents'] = 3000;
    expect(fn () => $this->service->prepare($this->tenant->id, $this->actor, $different))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('source identity and foreign store are rejected before preparation', function () {
    $wrong = $this->input;
    $wrong['source_url'] .= '?secret=must-not-persist';
    expect(fn () => $this->service->prepare($this->tenant->id, $this->actor, $wrong))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-pilot']);
    $this->store->update(['tenant_id' => $other->id]);
    expect(fn () => $this->service->prepare($this->tenant->id, $this->actor, $this->input))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(SubscriptionPilotMember::query()->count())->toBe(0);
});

test('prepaid plans retain independent charge and delivery intervals', function () {
    $this->input['lines'][0]['billing_months'] = 6;
    $this->input['lines'][0]['prepaid'] = true;
    $this->input['lines'][0]['finite_deliveries'] = 6;
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    expect($member->details['lines'][0])->toMatchArray(['billing_months' => 6, 'delivery_months' => 1, 'prepaid' => true, 'finite_deliveries' => 6]);
});

test('unpaid future cycle and disconnected providers remain unknown rather than successful or zero', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $report = $this->service->dashboard($this->tenant->id, $this->actor);
    expect($report['members'][0])->toMatchArray(['payment_status' => 'Awaiting cycle', 'duplicate_status' => 'Not verified', 'shopify_status' => 'Not verified'])
        ->and($report['payment_ready'])->toBeFalse()->and($report['recharge_connected'])->toBeFalse();
    $this->get('https://app.theeverbranch.com/subscriptions/pilot?tenant='.$this->tenant->slug)->assertOk()
        ->assertSeeText('Fictional member')->assertSeeText('Incomplete provider coverage')->assertSeeText('Recharge remains primary');
});

test('Shopify mirror of a payment is not counted as another charge', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(20, 0));
    $this->service->observe($this->tenant->id, $this->actor, $member->id, pilotObservation());
    $this->service->observe($this->tenant->id, $this->actor, $member->id, pilotObservation('shopify', 'order', 'order-44'));
    $report = $this->service->dashboard($this->tenant->id, $this->actor);
    expect($report['members'][0])->toMatchArray(['paid_cents' => 2500, 'duplicate_alert' => false, 'duplicate_status' => 'Not verified', 'shopify_status' => 'Observed order'])
        ->and($report['chart']['datasets'][0]['data'])->toBe([25.0])->and($report['chart']['datasets'][1]['data'])->toBe([null]);
});

test('a successful charge in both billing systems raises a possible duplicate alert', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(20, 0));
    foreach (['recharge', 'stripe'] as $provider) {
        $this->service->observe($this->tenant->id, $this->actor, $member->id, pilotObservation($provider));
    }
    expect($this->service->dashboard($this->tenant->id, $this->actor)['members'][0])->toMatchArray(['duplicate_alert' => true, 'duplicate_status' => 'Possible duplicate', 'paid_cents' => 5000]);
});

test('repeated observation cannot duplicate a payment or silently change its amount', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(20, 0));
    $one = $this->service->observe($this->tenant->id, $this->actor, $member->id, pilotObservation());
    $two = $this->service->observe($this->tenant->id, $this->actor, $member->id, pilotObservation());
    expect($two->id)->toBe($one->id)->and(SubscriptionPilotEvent::query()->count())->toBe(1);
    $conflict = pilotObservation();
    $conflict['amount_cents'] = 3000;
    expect(fn () => $this->service->observe($this->tenant->id, $this->actor, $member->id, $conflict))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $one->update(['status' => 'refunded']))->toThrow(\LogicException::class);
});

test('membership role and entitlement protect reporting and preparation', function (string $denial) {
    if ($denial === 'role') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    }
    if ($denial === 'inactive') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    }
    if ($denial === 'paywall') {
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    }
    $this->get('https://app.theeverbranch.com/subscriptions/pilot?tenant='.$this->tenant->slug)->assertForbidden();
})->with(['role', 'inactive', 'paywall']);

test('foreign member selection fails without exposing the other customer', function () {
    $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->get('https://app.theeverbranch.com/subscriptions/pilot?tenant='.$this->tenant->slug.'&member=99999')->assertNotFound();
});

function pilotShopifyOrder(array $changes = []): array
{
    return array_replace(['id' => '8888', 'customer' => ['id' => '456'], 'test' => false, 'currency' => 'USD', 'total_price' => '25.00',
        'processed_at' => '2026-11-02T14:00:00Z', 'updated_at' => '2026-11-02T15:00:00Z', 'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled',
        'note_attributes' => [['name' => 'rc_subscription_ids', 'value' => '789'], ['name' => 'rc_charge_id', 'value' => 'charge-123']],
        'transactions' => [['id' => 'txn-55', 'gateway' => 'shopify_payments', 'kind' => 'SALE', 'status' => 'SUCCESS', 'amount' => '25.00']]], $changes);
}

test('Shopify projection tracks only the exact reviewed customer and subscription and never duplicates imported payments', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(20, 0));
    expect($this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder(['customer' => ['id' => '777']])))->toBe(0);
    expect($this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder(['note_attributes' => []])))->toBe(0);
    $this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder());
    $this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder());
    expect(SubscriptionPilotEvent::query()->count())->toBe(3);
    expect($this->service->dashboard($this->tenant->id, $this->actor)['members'][0])->toMatchArray(['paid_cents' => 2500, 'shopify_status' => 'Observed order', 'fulfillment_status' => 'Observed fulfillment', 'duplicate_alert' => false]);
    $this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder(['id' => '9999', 'transactions' => [['id' => 'external', 'gateway' => '', 'kind' => 'SALE', 'status' => 'SUCCESS', 'amount' => '25.00']]]));
    expect(SubscriptionPilotEvent::query()->where('kind', 'payment')->count())->toBe(1);
});

test('late older Shopify payloads cannot replace a newer provider refund observation', function () {
    $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(20, 0));
    $this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder(['financial_status' => 'refunded', 'updated_at' => '2026-11-03T15:00:00Z']));
    $this->travel(1)->minutes();
    $this->service->projectShopifyOrder($this->tenant->id, 'pilot', pilotShopifyOrder());
    expect($this->service->dashboard($this->tenant->id, $this->actor)['members'][0])->toMatchArray(['paid_cents' => 0, 'shopify_status' => 'Not verified']);
});

test('pilot Shopify refresh uses only a fixed query and rechecks merchant identity before projection', function () {
    $member = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $this->providerReadExpected = true;
    Http::fake(['https://fictional-pilot.myshopify.com/admin/api/2026-10/graphql.json' => Http::response(['data' => [
        'shop' => ['myshopifyDomain' => 'foreign-store.myshopify.com'], 'orders' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
    ]])]);
    expect(fn () => app(\App\Services\Subscriptions\SubscriptionPilotShopifyReadService::class)->refresh($this->tenant->id, $this->actor, $member->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionPilotEvent::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => str_starts_with($request['query'], 'query PilotShopifyOrders') && ! str_contains($request['query'], 'mutation')
        && $request['variables']['query'] === 'customer_id:456 created_at:>=2026-05-01 test:false');
});

test('pilot dashboard renders two independent member readouts and accessible chart data', function () {
    $one = $this->service->prepare($this->tenant->id, $this->actor, $this->input);
    $two = $this->input;
    $two['name'] = 'Second fictional member';
    $two['email'] = 'second@example.test';
    $two['recharge_customer_id'] = '124';
    $two['shopify_customer_id'] = '457';
    $two['source_url'] = 'https://fictional.admin.rechargeapps.com/merchant/customers/124';
    $two['expected_amount_cents'] = 3000;
    $two['lines'][0]['unit_price_cents'] = 3000;
    $this->service->prepare($this->tenant->id, $this->actor, $two);
    foreach (['2026-08-02', '2026-09-02', '2026-10-02'] as $date) {
        $event = pilotObservation();
        $event['cycle_date'] = $date;
        $event['provider_reference'] = 'fixture-'.$date;
        $event['occurred_at'] = $date.'T10:00:00-04:00';
        $event['observed_at'] = '2026-10-27T10:00:00-04:00';
        $this->service->observe($this->tenant->id, $this->actor, $one->id, $event);
    }
    if (getenv('SUBSCRIPTIONS_PILOT_RENDER_PATH')) {
        $this->app->forgetInstance(\Illuminate\Foundation\Vite::class);
    }
    $response = $this->get('https://app.theeverbranch.com/subscriptions/pilot?tenant='.$this->tenant->slug);
    $response->assertOk()->assertSeeText('Second fictional member')->assertSeeText('View payment chart data')->assertSeeText('Not verified');
    if ($output = getenv('SUBSCRIPTIONS_PILOT_RENDER_PATH')) {
        file_put_contents($output, $response->getContent());
    }
    $this->get('https://app.theeverbranch.com/subscriptions/pilot?tenant='.$this->tenant->slug.'&member='.$one->id)->assertOk()->assertViewHas('pilot', fn ($p) => $p['selected'] === $one->id);
});
