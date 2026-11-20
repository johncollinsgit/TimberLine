<?php

namespace Tests\Unit;

use App\Jobs\SendWebsiteDropshipDraft;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsiteDropshipDraftHandoff;
use App\Services\ManagedWebsite\WebsiteDropshipOutbox;
use App\Services\ManagedWebsite\WebsitePaymentService;
use App\Services\ManagedWebsite\WebsiteStripeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\WebsiteStripeFixture;
use Tests\TestCase;

final class WebsiteDropshipDraftHandoffTest extends TestCase
{
    private const SHOP = 'supplier-fixture.myshopify.com';

    private const SHOP_ID = 'gid://shopify/Shop/12345';

    private const DRAFT_ID = 'gid://shopify/DraftOrder/98765';

    private WebsiteOrder $order;

    private WebsitePayment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        // The production write must be outside any outer transaction.
        config()->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Http::preventStrayRequests();

        $tenant = Tenant::query()->create(['name' => 'Retail seller', 'slug' => 'fixture-seller']);
        $site = TenantSite::query()->create(['tenant_id' => $tenant->id, 'subdomain' => 'fixture-seller']);
        config()->set([
            'website_dropshipping.shopify_drafts_enabled' => true,
            "website_dropshipping.shopify_suppliers.{$tenant->id}.{$site->id}.forestry" => [
                'shop_domain' => self::SHOP, 'shop_id' => self::SHOP_ID, 'admin_token' => 'fixture_supplier_only_token',
            ],
        ]);

        $this->order = WebsiteOrder::query()->create([
            'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'number' => 'WEB-POFIXTURE',
            'lookup_token' => 'private-lookup-token', 'source' => 'native', 'order_status' => 'open',
            'payment_status' => 'paid', 'paid_at' => now(), 'total_cents' => 3500,
            'fulfillment_method' => 'ship', 'fulfillment_status' => 'unfulfilled', 'currency' => 'usd',
            'customer_snapshot' => ['email' => 'buyer@example.invalid'],
            'shipping_address' => [
                'name' => 'Sample Buyer', 'street1' => '10 Main Street', 'city' => 'Portland',
                'state' => 'ME', 'zip' => '04101', 'country' => 'US', 'phone' => '2025550123',
            ],
        ]);
        $this->payment = $this->order->payments()->create([
            'tenant_id' => $tenant->id, 'provider' => 'stripe', 'status' => 'paid', 'currency' => 'usd',
            'amount_cents' => 3500, 'provider_payment_intent_id' => 'pi_supplier_fixture',
            'metadata' => [
                'checkout_version' => 1, 'paid_confirmed' => true, 'inventory_committed' => true,
                'livemode' => true, 'paid_total_cents' => 3500,
            ],
        ]);
        $this->order->lines()->create([
            'tenant_id' => $tenant->id, 'title' => 'Fixture candle', 'product_type' => 'physical',
            'quantity' => 2, 'unit_price_cents' => 1750, 'line_total_cents' => 3500,
            'snapshot' => ['dropshipping' => ['provider' => 'shopify', 'supplier' => 'forestry', 'variant' => '332211', 'location_id' => 10]],
        ]);
    }

    public function test_operator_submits_one_private_zero_dollar_supplier_draft(): void
    {
        Http::fake(['https://'.self::SHOP.'/*' => function (Request $request) {
            $this->assertTrue($request->hasHeader('X-Shopify-Access-Token', 'fixture_supplier_only_token'));
            if (str_contains($request['query'], 'query SupplierShop')) {
                return $this->shopResponse();
            }
            $this->assertStringContainsString('draftOrderCreate', $request['query']);
            $this->assertDatabaseCount('website_dropship_drafts', 1);
            $this->assertSame(0, DB::transactionLevel());
            $input = $request['variables']['input'];
            $this->assertFalse($input['visibleToCustomer']);
            $this->assertSame('WEB-POFIXTURE', $input['poNumber']);
            $this->assertArrayNotHasKey('email', $input);
            $this->assertArrayNotHasKey('customerId', $input);
            $this->assertArrayNotHasKey('billingAddress', $input);
            $this->assertSame([
                'firstName' => 'Sample', 'lastName' => 'Buyer', 'address1' => '10 Main Street',
                'city' => 'Portland', 'provinceCode' => 'ME', 'zip' => '04101', 'countryCode' => 'US',
            ], $input['shippingAddress']);
            $this->assertSame([[
                'variantId' => 'gid://shopify/ProductVariant/332211', 'quantity' => 2,
                'priceOverride' => ['amount' => '0.00', 'currencyCode' => 'USD'],
            ]], $input['lineItems']);

            return Http::response(['data' => ['draftOrderCreate' => ['draftOrder' => $this->draft($input['tags']), 'userErrors' => []]]]);
        }]);

        $args = ['tenant' => $this->order->tenant_id, 'site' => $this->order->tenant_site_id, 'order' => $this->order->id];
        $this->artisan('website:dropship-draft', $args + ['--submit' => true])->assertSuccessful();
        $this->artisan('website:dropship-draft', $args + ['--submit' => true])->assertExitCode(1);
        $this->artisan('website:dropship-draft', $args + ['--reconcile' => true])->assertSuccessful();

        $this->assertDatabaseHas('website_dropship_drafts', ['website_order_id' => $this->order->id, 'shopify_draft_id' => self::DRAFT_ID]);
        $this->assertSame('unfulfilled', $this->order->fresh()->fulfillment_status);
        Http::assertSentCount(2);
    }

    public function test_a_lost_response_requires_read_only_reconciliation(): void
    {
        $createAttempts = 0;
        $includeDraft = false;
        $tags = [];
        Http::fake(['https://'.self::SHOP.'/*' => function (Request $request) use (&$createAttempts, &$tags, &$includeDraft) {
            if (str_contains($request['query'], 'query SupplierShop')) {
                return $this->shopResponse();
            }
            if (str_contains($request['query'], 'mutation SupplierDraftCreate')) {
                $createAttempts++;
                $tags = $request['variables']['input']['tags'];
                throw new ConnectionException('fixture timeout');
            }
            $this->assertStringContainsString('query SupplierDraftLookup', $request['query']);
            $this->assertSame('tag:'.$tags[1], $request['variables']['filter']);

            return Http::response(['data' => ['draftOrders' => [
                'pageInfo' => ['hasNextPage' => false],
                'nodes' => $includeDraft ? [$this->draft($tags)] : [],
            ]]]);
        }]);

        $this->expectHandoffFailure('Shopify did not respond.');
        $this->expectHandoffFailure('already attempted');
        $this->assertDatabaseHas('website_dropship_drafts', ['website_order_id' => $this->order->id, 'shopify_draft_id' => null]);
        try {
            app(WebsiteDropshipDraftHandoff::class)->reconcile(...$this->orderArgs());
            $this->fail('A missing indexed match must leave the claim open.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('remains unverified', $exception->getMessage());
        }

        $includeDraft = true;
        $this->assertSame(self::DRAFT_ID, app(WebsiteDropshipDraftHandoff::class)->reconcile(...$this->orderArgs()));
        $this->assertSame(1, $createAttempts);
    }

    public function test_wrong_shop_is_blocked_before_a_claim_or_address_transfer(): void
    {
        Http::fake(['https://'.self::SHOP.'/*' => Http::response(['data' => [
            'shop' => ['id' => 'gid://shopify/Shop/50', 'myshopifyDomain' => self::SHOP],
        ]])]);
        $this->expectHandoffFailure('does not match');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('website_dropship_drafts', 0);
    }

    public function test_handoff_requires_payment_proof_and_the_seller_site(): void
    {
        Http::fake();
        $this->expectHandoffFailure('native Website checkout', [$this->order->tenant_id + 1, $this->order->tenant_site_id, $this->order->id]);
        $this->payment->update(['metadata' => [...$this->payment->metadata, 'livemode' => false]]);
        $this->expectHandoffFailure('Verified live Stripe payment');
        Http::assertNothingSent();
        $this->assertDatabaseCount('website_dropship_drafts', 0);
    }

    public function test_verified_payment_records_once_and_the_queue_submits_outside_checkout(): void
    {
        $this->enableAutomatic();
        $this->pendingStripePayment();
        Bus::fake([SendWebsiteDropshipDraft::class]);
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/*' => fn () => Http::response($this->liveSession()),
            'https://api.stripe.com/v1/refunds*' => Http::response(['data' => [], 'has_more' => false]),
            'https://'.self::SHOP.'/*' => fn (Request $request) => str_contains($request['query'], 'query SupplierShop')
                ? $this->shopResponse()
                : Http::response(['data' => ['draftOrderCreate' => ['draftOrder' => $this->draft($request['variables']['input']['tags']), 'userErrors' => []]]]),
        ]);
        $payments = app(WebsitePaymentService::class);
        $payments->reconcile($this->payment);
        $payments->reconcile($this->payment);
        $this->assertSame('paid', $this->order->fresh()->payment_status);
        $this->assertDatabaseCount('website_dropship_outbox', 1);
        $this->assertDatabaseCount('website_dropship_drafts', 0);
        Bus::assertNotDispatched(SendWebsiteDropshipDraft::class);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::SHOP));

        $this->artisan('website:dispatch-dropship-drafts')->assertSuccessful();
        $job = Bus::dispatched(SendWebsiteDropshipDraft::class)->sole();
        $outbox = app(WebsiteDropshipOutbox::class);
        $job->handle($outbox);
        $job->handle($outbox);

        $this->assertDatabaseHas('website_dropship_outbox', ['website_order_id' => $this->order->id, 'status' => 'submitted', 'attempts' => 1]);
        $this->assertDatabaseHas('website_dropship_drafts', ['website_order_id' => $this->order->id, 'shopify_draft_id' => self::DRAFT_ID]);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), self::SHOP)
            && str_contains($request['query'], 'draftOrderCreate')));
    }

    public function test_payment_rollback_and_unapproved_supplier_leave_no_outbox(): void
    {
        $this->pendingStripePayment();
        Bus::fake([SendWebsiteDropshipDraft::class]);
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/*' => fn () => Http::response($this->liveSession()),
            'https://api.stripe.com/v1/refunds*' => Http::response(['data' => [], 'has_more' => false]),
        ]);
        $payments = app(WebsitePaymentService::class);
        $this->enableAutomatic();

        DB::beginTransaction();
        try {
            $payments->reconcile($this->payment);
            $this->assertDatabaseCount('website_dropship_outbox', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('website_dropship_outbox', 0);
        $this->assertSame('pending', $this->order->fresh()->payment_status);

        $this->enableAutomatic(false);
        $payments->reconcile($this->payment->refresh());
        $this->assertSame('paid', $this->order->fresh()->payment_status);
        $this->assertDatabaseCount('website_dropship_outbox', 0);
        Bus::assertNotDispatched(SendWebsiteDropshipDraft::class);
    }

    public function test_lost_queue_jobs_are_replaced_and_lost_create_reconciles_read_only(): void
    {
        $this->enableAutomatic();
        $outbox = $this->recordAutomaticOrder();
        Bus::fake([SendWebsiteDropshipDraft::class]);
        $tags = [];
        $calls = [];
        Http::fake([
            'https://api.stripe.com/v1/refunds*' => Http::response(['data' => [], 'has_more' => false]),
            'https://'.self::SHOP.'/*' => function (Request $request) use (&$tags, &$calls) {
                $calls[] = $request['query'];
                if (str_contains($request['query'], 'query SupplierShop')) {
                    return $this->shopResponse();
                }
                if (str_contains($request['query'], 'mutation SupplierDraftCreate')) {
                    $tags = $request['variables']['input']['tags'];
                    throw new ConnectionException('fixture timeout');
                }

                return Http::response(['data' => ['draftOrders' => [
                    'pageInfo' => ['hasNextPage' => false], 'nodes' => [$this->draft($tags)],
                ]]]);
            },
        ]);

        $this->assertSame(1, $outbox->scan(10)['queued']);
        $first = Bus::dispatched(SendWebsiteDropshipDraft::class)->sole();
        $this->travel(6)->minutes();
        $this->assertSame(1, $outbox->scan(10)['queued']);
        $first->handle($outbox);
        Http::assertNothingSent();
        Bus::dispatched(SendWebsiteDropshipDraft::class)->last()->handle($outbox);
        $this->assertDatabaseHas('website_dropship_outbox', ['status' => 'pending', 'attempts' => 1]);
        $this->assertDatabaseHas('website_dropship_drafts', ['website_order_id' => $this->order->id, 'shopify_draft_id' => null]);

        $this->travel(3)->minutes();
        $this->assertSame(1, $outbox->scan(10)['queued']);
        Bus::dispatched(SendWebsiteDropshipDraft::class)->last()->handle($outbox);
        $this->assertDatabaseHas('website_dropship_outbox', ['status' => 'submitted', 'attempts' => 2]);
        $this->assertCount(1, array_filter($calls, fn (string $query) => str_contains($query, 'draftOrderCreate')));
        $this->assertCount(1, array_filter($calls, fn (string $query) => str_contains($query, 'query SupplierDraftLookup')));
    }

    public function test_refund_preflight_blocks_remote_handoff(): void
    {
        $this->enableAutomatic();
        $outbox = $this->recordAutomaticOrder();
        Bus::fake([SendWebsiteDropshipDraft::class]);
        Http::fake([
            'https://api.stripe.com/v1/refunds*' => Http::response(['data' => [[
                'id' => 're_test_pending', 'payment_intent' => $this->payment->provider_payment_intent_id,
                'amount' => 100, 'currency' => 'usd', 'status' => 'pending',
            ]], 'has_more' => false]),
        ]);
        $outbox->scan(10);
        Bus::dispatched(SendWebsiteDropshipDraft::class)->sole()->handle($outbox);
        $this->assertDatabaseHas('website_dropship_outbox', ['status' => 'review']);
        $this->assertDatabaseCount('website_dropship_drafts', 0);
        Http::assertSentCount(1);
    }

    public function test_a_reconfigured_shop_cannot_receive_a_queued_order(): void
    {
        $this->enableAutomatic();
        $outbox = $this->recordAutomaticOrder();
        Bus::fake([SendWebsiteDropshipDraft::class]);
        Http::fake();
        $outbox->scan(10);
        $key = "website_dropshipping.shopify_suppliers.{$this->order->tenant_id}.{$this->order->tenant_site_id}.forestry";
        config()->set($key.'.shop_id', 'gid://shopify/Shop/88888');
        Bus::dispatched(SendWebsiteDropshipDraft::class)->sole()->handle($outbox);
        $this->assertDatabaseHas('website_dropship_outbox', ['status' => 'review']);
        Http::assertNothingSent();
    }

    private function enableAutomatic(bool $enabled = true): void
    {
        $key = "website_dropshipping.shopify_suppliers.{$this->order->tenant_id}.{$this->order->tenant_site_id}.forestry";
        config()->set([$key.'.automatic' => $enabled, 'services.stripe.secret' => 'sk_live_synthetic_fixture']);
        $this->payment->update(['metadata' => [
            ...$this->payment->metadata, 'platform_fingerprint' => app(WebsiteStripeService::class)->fingerprint(),
        ]]);
    }

    private function pendingStripePayment(): void
    {
        $this->order->update(['payment_status' => 'pending', 'paid_at' => null, 'subtotal_cents' => 3500]);
        $this->payment->update([
            'status' => 'pending', 'provider_session_id' => 'cs_fixture_supplier', 'provider_payment_intent_id' => null,
            'metadata' => [
                'checkout_version' => 1, 'livemode' => true, 'nonce' => 'fixture_nonce', 'account_id' => 'acct_merchant',
                'customer_id' => 'cus_supplier_fixture', 'application_fee_cents' => 0,
                'platform_fingerprint' => app(WebsiteStripeService::class)->fingerprint(),
            ],
        ]);
    }

    private function liveSession(): array
    {
        $session = WebsiteStripeFixture::session($this->order->fresh());
        $session['livemode'] = $session['payment_intent']['livemode'] = true;

        return $session;
    }

    private function recordAutomaticOrder(): WebsiteDropshipOutbox
    {
        $outbox = app(WebsiteDropshipOutbox::class);
        DB::transaction(fn () => $outbox->record($this->order, $this->payment->id));

        return $outbox;
    }

    private function orderArgs(): array
    {
        return [$this->order->tenant_id, $this->order->tenant_site_id, $this->order->id];
    }

    private function expectHandoffFailure(string $message, ?array $args = null): void
    {
        try {
            app(WebsiteDropshipDraftHandoff::class)->submit(...($args ?? $this->orderArgs()));
            $this->fail('Expected handoff to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function shopResponse(): mixed
    {
        return Http::response(['data' => ['shop' => ['id' => self::SHOP_ID, 'myshopifyDomain' => self::SHOP]]]);
    }

    private function draft(array $tags): array
    {
        return [
            'id' => self::DRAFT_ID, 'status' => 'OPEN', 'tags' => $tags, 'visibleToCustomer' => false,
            'totalPriceSet' => ['shopMoney' => ['amount' => '0.00']],
        ];
    }
}
