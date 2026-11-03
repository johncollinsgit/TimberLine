<?php

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\PaymentQuoteService;
use App\Services\PaymentsCore\Quotes\QuoteException;
use App\Services\PaymentsCore\Quotes\TaxAssessment;
use App\Services\PaymentsCore\Quotes\TaxPolicy;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Explicit fictional tax evidence. Never registered by application code. */
final class IndependentReviewedZeroTax implements TaxPolicy
{
    public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment
    {
        return new TaxAssessment('independent_fixture_only', 'reviewed_zero_v1', TaxAssessment::scope($binding, $siteId),
            TaxAssessment::terms($lines), 'usd', 'exclusive', 'per_line_half_up',
            array_fill_keys(array_map(fn ($line) => $line->lineId, $lines), 0), $at->modify('-1 minute'), $at->modify('+1 hour'));
    }
}

function independentQuoteFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Independent quote merchant', 'slug' => 'independent-quote-'.Str::lower(Str::ulid())]);
    $site = TenantSite::query()->create(['tenant_id' => $tenant->id, 'subdomain' => $tenant->slug, 'status' => 'draft', 'settings' => []]);
    $product = WebsiteProduct::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id,
        'handle' => 'independent-product', 'title' => 'Independent fictional product', 'product_type' => 'physical',
        'status' => 'active', 'track_inventory' => true, 'service_details' => ['catalog_revision' => 1]]);
    $variant = WebsiteProductVariant::query()->create(['tenant_id' => $tenant->id, 'website_product_id' => $product->id,
        'title' => 'Fictional item', 'sku' => 'INDEPENDENT-SKU', 'price_cents' => 1900, 'inventory_quantity' => 5, 'is_available' => true]);
    $binding = new MerchantBinding($tenant->id, 'independent_counter', 'fixture_gateway', 'fictional_account', 'fictional_location', 'test');
    $catalog = new WebsitePaymentCatalog(DB::connection());
    $repository = new DatabaseQuoteRepository(DB::connection(), app('encrypter'));

    return [$tenant, $site, $product, $variant, $binding, $catalog, $repository];
}

function independentQuoteItems(WebsitePaymentCatalog $catalog, MerchantBinding $binding, int $siteId, int $variantId): array
{
    return [['variant_id' => $variantId, 'quantity' => 1,
        'expected_revision' => $catalog->item($binding->tenantId, $siteId, $variantId)->revision]];
}

function independentQuoteDenied(callable $call, string $code, ?int $status = null): void
{
    try {
        $call();
    } catch (QuoteException $exception) {
        expect($exception->blockerCode)->toBe($code);
        if ($status !== null) {
            expect($exception->httpStatus)->toBe($status);
        }

        return;
    }
    throw new RuntimeException('Expected quote denial: '.$code);
}

beforeEach(fn () => Http::preventStrayRequests());

it('independently refuses missing tax rather than manufacturing a zero tax quote', function (): void {
    [, $site, , $variant, $binding, $catalog, $repository] = independentQuoteFixture();
    $service = new PaymentQuoteService($catalog, $repository);
    independentQuoteDenied(fn () => $service->createQuote($binding, 111, $site->id, (string) Str::uuid(),
        independentQuoteItems($catalog, $binding, $site->id, $variant->id)), 'tax_policy_missing', 503);
    expect(DB::table('payment_core_quotes')->count())->toBe(0)
        ->and(DB::table('payment_core_operations')->count())->toBe(0);
});

it('independently rejects client authority fields before server quotation', function (string $field, mixed $value): void {
    [, $site, , $variant, $binding, $catalog, $repository] = independentQuoteFixture();
    $items = independentQuoteItems($catalog, $binding, $site->id, $variant->id);
    $items[0][$field] = $value;
    $service = new PaymentQuoteService($catalog, $repository, new IndependentReviewedZeroTax);
    independentQuoteDenied(fn () => $service->createQuote($binding, 111, $site->id, (string) Str::uuid(), $items), 'invalid_selection');
    expect(DB::table('payment_core_quotes')->count())->toBe(0);
})->with([
    'price' => ['unit_amount_minor', 1], 'tax' => ['tax_amount_minor', 0], 'kind' => ['kind', 'postage'],
    'tenant' => ['tenant_id', 13], 'merchant' => ['merchant_account', 'unrelated'], 'sale' => ['sale_id', 'replacement'],
]);

it('independently preserves historical quote replay while rejecting a newly stale collection', function (): void {
    [, $site, , $variant, $binding, $catalog, $repository] = independentQuoteFixture();
    $service = new PaymentQuoteService($catalog, $repository, new IndependentReviewedZeroTax);
    $key = (string) Str::uuid();
    $items = independentQuoteItems($catalog, $binding, $site->id, $variant->id);
    $quote = $service->createQuote($binding, 111, $site->id, $key, $items);
    $variant->update(['price_cents' => 2000]);
    $replay = $service->createQuote($binding, 111, $site->id, strtoupper($key), $items);
    expect($replay->id)->toBe($quote->id)->and($replay->saleId)->toBe($quote->saleId)->and($replay->amountMinor)->toBe(1900);
    expect($service->loadIdentity($binding, 111, $site->id, $quote->id)->id)->toBe($quote->id);
    independentQuoteDenied(fn () => $service->loadForAttempt($binding, 111, $site->id, $quote->id), 'catalog_changed', 409);
    expect(DB::table('payment_core_operations')->count())->toBe(0)
        ->and(DB::table('website_inventory_reservations')->count())->toBe(0);
});

it('independently preserves quote identity after expiry but refuses a new collection', function (): void {
    [, $site, , $variant, $binding, $catalog, $repository] = independentQuoteFixture();
    $time = new DateTimeImmutable('2026-10-22T12:00:00+00:00');
    $service = new PaymentQuoteService($catalog, $repository, new IndependentReviewedZeroTax, function () use (&$time) {
        return $time;
    });
    $quote = $service->createQuote($binding, 111, $site->id, (string) Str::uuid(), independentQuoteItems($catalog, $binding, $site->id, $variant->id));
    $time = $time->modify('+10 minutes');
    expect($service->loadIdentity($binding, 111, $site->id, $quote->id)->saleId)->toBe($quote->saleId);
    independentQuoteDenied(fn () => $service->loadForAttempt($binding, 111, $site->id, $quote->id), 'quote_expired', 409);
    independentQuoteDenied(fn () => $service->loadIdentity($binding, 112, $site->id, $quote->id), 'quote_not_found', 404);
});

it('independently refuses stored quote scalar or ciphertext corruption', function (): void {
    [, $site, , $variant, $binding, $catalog, $repository] = independentQuoteFixture();
    $service = new PaymentQuoteService($catalog, $repository, new IndependentReviewedZeroTax);
    $quote = $service->createQuote($binding, 111, $site->id, (string) Str::uuid(), independentQuoteItems($catalog, $binding, $site->id, $variant->id));
    $saved = DB::table('payment_core_quotes')->where('quote_id', $quote->id)->first();
    DB::table('payment_core_quotes')->where('quote_id', $quote->id)->update(['site_id' => $site->id + 1]);
    independentQuoteDenied(fn () => $repository->findById($binding->tenantId, $quote->id), 'quote_evidence_invalid', 503);
    DB::table('payment_core_quotes')->where('quote_id', $quote->id)->update(['site_id' => $site->id, 'snapshot' => $saved->snapshot.'corrupt']);
    independentQuoteDenied(fn () => $repository->findById($binding->tenantId, $quote->id), 'quote_evidence_invalid', 503);
});

it('independently cannot disguise generic service or postage titles as approved printing', function (): void {
    [, $site, $product, $variant, $binding, $catalog] = independentQuoteFixture();
    $product->update(['product_type' => 'service', 'title' => 'Printing, color copies and postage', 'track_inventory' => false]);
    independentQuoteDenied(fn () => $catalog->item($binding->tenantId, $site->id, $variant->id), 'service_policy_missing');
    $product->update(['product_type' => 'quote']);
    independentQuoteDenied(fn () => $catalog->item($binding->tenantId, $site->id, $variant->id), 'catalog_kind_unsupported');
});

it('independently fails closed on unknown inventory and foreign site identities', function (): void {
    [$tenant, $site, , $variant, $binding, $catalog] = independentQuoteFixture();
    $variant->update(['inventory_quantity' => null]);
    independentQuoteDenied(fn () => $catalog->item($binding->tenantId, $site->id, $variant->id), 'inventory_unknown', 409);
    $foreignTenant = Tenant::query()->create(['name' => 'Independent foreign merchant', 'slug' => $tenant->slug.'-foreign']);
    $foreign = TenantSite::query()->create(['tenant_id' => $foreignTenant->id, 'subdomain' => $foreignTenant->slug, 'status' => 'draft', 'settings' => []]);
    independentQuoteDenied(fn () => $catalog->item($binding->tenantId, $foreign->id, $variant->id), 'catalog_item_not_found', 404);
});

it('independently detects exact SKU ambiguity regardless of cursor and limit', function (): void {
    [$tenant, $site, , $first, $binding, $catalog] = independentQuoteFixture();
    $secondProduct = WebsiteProduct::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id,
        'handle' => 'independent-duplicate', 'title' => 'Second fictional product', 'product_type' => 'physical', 'status' => 'active', 'track_inventory' => false]);
    WebsiteProductVariant::query()->create(['tenant_id' => $tenant->id, 'website_product_id' => $secondProduct->id,
        'title' => 'Second fictional variant', 'sku' => $first->sku, 'price_cents' => 2500, 'is_available' => true]);
    independentQuoteDenied(fn () => $catalog->listing($binding->tenantId, $site->id, null, 1, $first->sku), 'catalog_sku_ambiguous', 409);
    independentQuoteDenied(fn () => $catalog->listing($binding->tenantId, $site->id, (string) $first->id, 1, $first->sku), 'invalid_catalog_query', 422);
});
