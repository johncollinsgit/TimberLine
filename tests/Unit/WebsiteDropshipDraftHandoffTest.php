<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsiteDropshipDraftHandoff;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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
