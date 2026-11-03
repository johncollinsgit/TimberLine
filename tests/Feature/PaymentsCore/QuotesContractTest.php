<?php

use App\Models\WebsiteInventoryReservation;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\FixedPrintingMapping;
use App\Services\PaymentsCore\Quotes\PaymentQuoteService;
use App\Services\PaymentsCore\Quotes\QuoteException;
use App\Services\PaymentsCore\Quotes\ServiceItemPolicy;
use App\Services\PaymentsCore\Quotes\TaxAssessment;
use App\Services\PaymentsCore\Quotes\TaxPolicy;
use App\Services\PaymentsCore\Quotes\UnavailableTaxPolicy;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PosFixture;

/** Fictional test-only policy. It is never registered in an application container. */
final class QuotesFictionalTax implements TaxPolicy
{
    public int $rateBps = 725;

    public string $revision = 'fictional_reviewed_policy_v1';

    public bool $wrongScope = false;

    public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment
    {
        $taxes = [];
        foreach ($lines as $line) {
            $taxes[$line->lineId] = intdiv($line->quantity * $line->unitAmountMinor * $this->rateBps + 5000, 10000);
        }

        return new TaxAssessment('fictional_test_tax_authority', $this->revision,
            $this->wrongScope ? str_repeat('0', 64) : TaxAssessment::scope($binding, $siteId), TaxAssessment::terms($lines),
            'usd', 'exclusive', 'per_line_half_up', $taxes, $at->modify('-1 minute'), $at->modify('+10 minutes'));
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->fixture = PosFixture::merchant('quote-'.Str::lower(Str::random(8)));
    $this->binding = new MerchantBinding($this->fixture->tenant->id, 'fictional_counter', 'fixture_provider', 'fictional_alpha_account', 'fictional_location', 'test');
    $this->catalog = new WebsitePaymentCatalog(DB::connection());
    $this->repository = new DatabaseQuoteRepository(DB::connection(), app('encrypter'));
    $this->tax = new QuotesFictionalTax;
    $this->clock = new DateTimeImmutable('2030-01-02T12:00:00+00:00');
    $this->service = new PaymentQuoteService($this->catalog, $this->repository, $this->tax, fn () => $this->clock);
    $this->selection = fn (int $quantity = 1) => [['variant_id' => $this->fixture->variant->id, 'quantity' => $quantity,
        'expected_revision' => $this->catalog->item($this->binding->tenantId, $this->fixture->site->id, $this->fixture->variant->id, $quantity)->revision]];
    $this->issue = fn (?string $key = null, ?array $items = null) => $this->service->createQuote($this->binding, $this->fixture->cashier->id,
        $this->fixture->site->id, $key ?? (string) Str::uuid(), $items ?? ($this->selection)());
    $this->load = fn ($quote) => $this->service->loadForAttempt($this->binding, $this->fixture->cashier->id, $this->fixture->site->id, $quote->id);
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('persists encrypted server-owned quotation and keeps its sale identity across restart and canonical UUID replay', function (): void {
    $key = (string) Str::uuid();
    $quote = ($this->issue)($key, ($this->selection)(2));
    expect($quote->currency)->toBe('usd')->and($quote->amountMinor)->toBe(5363)
        ->and($quote->lines[0]['unit_amount_minor'])->toBe(2500)->and($quote->lines[0]['tax_amount_minor'])->toBe(363)
        ->and($quote->lines[0]['kind'])->toBe('merchandise')->and($quote->toSaleSnapshot()->amountMinor)->toBe(5363);
    $newRepository = new DatabaseQuoteRepository(DB::connection(), app('encrypter'));
    $newService = new PaymentQuoteService($this->catalog, $newRepository, new UnavailableTaxPolicy, fn () => $this->clock);
    $replay = $newService->createQuote($this->binding, $this->fixture->cashier->id, $this->fixture->site->id, strtoupper($key), ($this->selection)(2));
    expect($replay->id)->toBe($quote->id)->and($replay->saleId)->toBe($quote->saleId)->and($replay->projection())->toBe($quote->projection())
        ->and(DB::table('payment_core_quotes')->count())->toBe(1);
    $raw = DB::table('payment_core_quotes')->first();
    expect($raw->snapshot)->not->toContain('fictional_alpha_account')->not->toContain('unit_amount_minor')
        ->and(json_encode($quote->projection()))->not->toContain('merchant_account')->not->toContain('binding_fingerprint');
    expect(($this->load)($quote)->saleId)->toBe($quote->saleId)
        ->and(WebsiteInventoryReservation::count())->toBe(0)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
});

it('rejects every caller-controlled price tax kind routing or card field', function (string $field): void {
    $items = ($this->selection)();
    $items[0][$field] = 'untrusted';
    expect(fn () => ($this->issue)(null, $items))->toThrow(QuoteException::class, 'invalid_selection')
        ->and(DB::table('payment_core_quotes')->count())->toBe(0);
})->with(['unit_amount_minor', 'tax_amount_minor', 'kind', 'provider', 'merchant_account', 'tenant_id', 'site_id', 'sale_id', 'pan', 'customer']);

it('refuses malformed selection and noncanonical revision fields', function (string $change): void {
    $items = ($this->selection)();
    match ($change) {
        'duplicate' => $items[] = $items[0],
        'float quantity' => $items[0]['quantity'] = 1.0,
        'string quantity' => $items[0]['quantity'] = '1',
        'zero quantity' => $items[0]['quantity'] = 0,
        'large quantity' => $items[0]['quantity'] = 100,
        'bad revision' => $items[0]['expected_revision'] = 'unverified',
        'foreign key type' => $items[0]['variant_id'] = (string) $items[0]['variant_id'],
    };
    expect(fn () => ($this->issue)(null, $items))->toThrow(QuoteException::class, 'invalid_selection');
})->with(['duplicate', 'float quantity', 'string quantity', 'zero quantity', 'large quantity', 'bad revision', 'foreign key type']);

it('never treats absent tax as zero and accepts zero only from explicit tax evidence', function (): void {
    $closed = new PaymentQuoteService($this->catalog, $this->repository);
    expect(fn () => $closed->createQuote($this->binding, $this->fixture->cashier->id, $this->fixture->site->id,
        (string) Str::uuid(), ($this->selection)()))->toThrow(QuoteException::class, 'tax_policy_missing')
        ->and(DB::table('payment_core_quotes')->count())->toBe(0);
    $this->tax->rateBps = 0;
    $quote = ($this->issue)();
    expect($quote->amountMinor)->toBe(2500)->and($quote->lines[0]['tax_amount_minor'])->toBe(0)
        ->and($quote->projection()['tax_policy_revision'])->toBe('fictional_reviewed_policy_v1');
});

it('rejects wrong tax currency expired evidence missing lines or mismatched scope', function (string $change): void {
    $line = $this->catalog->item($this->binding->tenantId, $this->fixture->site->id, $this->fixture->variant->id);
    $scope = $change === 'wrong scope' ? str_repeat('0', 64) : TaxAssessment::scope($this->binding, $this->fixture->site->id);
    $assessment = fn () => new TaxAssessment('test_authority', 'test_revision', $scope, TaxAssessment::terms([$line]),
        $change === 'currency' ? 'eur' : 'usd', 'exclusive', 'per_line_half_up',
        $change === 'missing line' ? [] : [$line->lineId => 1], $this->clock->modify('-2 hours'),
        $change === 'expired' ? $this->clock->modify('-1 hour') : $this->clock->modify('+1 hour'));
    expect(fn () => $assessment()->validate($this->binding, $this->fixture->site->id, [$line], $this->clock))->toThrow(QuoteException::class);
})->with(['wrong scope', 'currency', 'missing line', 'expired']);

it('returns the frozen quote on replay after price change but rejects a new attempt or stale selection', function (): void {
    $items = ($this->selection)();
    $quote = ($this->issue)(null, $items);
    $this->fixture->variant->update(['price_cents' => 2501]);
    expect(($this->issue)($quote->requestId, $items)->id)->toBe($quote->id)
        ->and($this->service->loadIdentity($this->binding, $this->fixture->cashier->id, $this->fixture->site->id, $quote->id)->saleId)->toBe($quote->saleId);
    expect(fn () => ($this->load)($quote))->toThrow(QuoteException::class, 'catalog_changed');
    expect(fn () => ($this->issue)(null, $items))->toThrow(QuoteException::class, 'catalog_changed');
});

it('rejects changed catalogue revision and tax policy revisions or recalculated amounts', function (string $change): void {
    $quote = ($this->issue)();
    match ($change) {
        'catalog' => $this->fixture->product->update(['service_details' => ['catalog_revision' => 9]]),
        'tax revision' => $this->tax->revision = 'fictional_reviewed_policy_v2',
        'tax amount' => $this->tax->rateBps = 800,
        'tax scope' => $this->tax->wrongScope = true,
    };
    expect(fn () => ($this->load)($quote))->toThrow(QuoteException::class);
})->with(['catalog', 'tax revision', 'tax amount', 'tax scope']);

it('preserves historical identity when expired while denying a new attempt and changed versions', function (): void {
    $quote = ($this->issue)();
    $this->clock = $quote->expiresAt;
    expect($this->service->loadIdentity($this->binding, $this->fixture->cashier->id, $this->fixture->site->id, $quote->id)->saleId)->toBe($quote->saleId);
    expect(fn () => ($this->load)($quote))->toThrow(QuoteException::class, 'quote_expired');
    expect(fn () => $this->service->loadIdentity($this->binding, $this->fixture->cashier->id, $this->fixture->site->id, $quote->id, 2))
        ->toThrow(QuoteException::class, 'quote_conflict');
});

it('does not let a request or owned quote change actor site register tenant or merchant binding', function (): void {
    $quote = ($this->issue)();
    $changedBinding = new MerchantBinding($this->binding->tenantId, $this->binding->registerId, $this->binding->provider,
        'different_merchant', $this->binding->locationId, 'test');
    expect(fn () => $this->service->createQuote($changedBinding, $this->fixture->cashier->id, $this->fixture->site->id, $quote->requestId, $quote->items))
        ->toThrow(QuoteException::class, 'quote_conflict');
    expect(fn () => $this->service->loadIdentity($changedBinding, $this->fixture->cashier->id, $this->fixture->site->id, $quote->id))
        ->toThrow(QuoteException::class, 'quote_binding_changed');
    expect(fn () => $this->service->loadIdentity($this->binding, $this->fixture->manager->id, $this->fixture->site->id, $quote->id))
        ->toThrow(QuoteException::class, 'quote_not_found');
    expect(fn () => $this->service->loadIdentity($this->binding, $this->fixture->cashier->id, $this->fixture->site->id + 999, $quote->id))
        ->toThrow(QuoteException::class, 'quote_not_found');
    $items = $quote->items;
    $items[0]['quantity']++;
    expect(fn () => ($this->issue)($quote->requestId, $items))->toThrow(QuoteException::class, 'quote_conflict');
});

it('checks foreign product site tenant and variant ownership without trusting client scope', function (): void {
    $other = PosFixture::merchant('quote-other-'.Str::lower(Str::random(6)));
    expect(fn () => $this->catalog->item($this->binding->tenantId, $this->fixture->site->id, $other->variant->id))->toThrow(QuoteException::class, 'catalog_item_not_found');
    $this->fixture->product->update(['tenant_site_id' => $other->site->id, 'handle' => 'mismatched-foreign-site']);
    expect(fn () => $this->catalog->item($this->binding->tenantId, $this->fixture->site->id, $this->fixture->variant->id))->toThrow(QuoteException::class, 'catalog_item_not_found');
    expect(fn () => $this->catalog->item($this->binding->tenantId, $other->site->id, $this->fixture->variant->id))->toThrow(QuoteException::class, 'catalog_item_not_found');
});

it('refuses unknown insufficient or reserved stock without making any hold or inventory mutation', function (string $change): void {
    match ($change) {
        'unknown' => $this->fixture->variant->update(['inventory_quantity' => null]),
        'insufficient' => $this->fixture->variant->update(['inventory_quantity' => 0]),
        'reserved' => WebsiteInventoryReservation::create(['tenant_id' => $this->binding->tenantId,
            'website_order_id' => $this->fixture->websiteOrder()->id, 'website_product_variant_id' => $this->fixture->variant->id,
            'quantity' => 5, 'status' => 'active', 'expires_at' => now()->subMinute()]),
    };
    $before = WebsiteInventoryReservation::count();
    expect(fn () => ($this->selection)())->toThrow(QuoteException::class)
        ->and(WebsiteInventoryReservation::count())->toBe($before)->and(DB::table('payment_core_quotes')->count())->toBe(0);
})->with(['unknown', 'insufficient', 'reserved']);

it('does not infer printing or postage from service titles metadata or quote-only products', function (string $type): void {
    $this->fixture->product->update(['product_type' => $type, 'title' => 'Fixed Printing and Postage', 'track_inventory' => false,
        'service_details' => ['payment_kind' => 'printing_service', 'fixed_price' => true]]);
    expect(fn () => ($this->selection)())->toThrow(QuoteException::class);
})->with(['service', 'quote', 'postage']);

it('allows only an explicitly reviewed fixed printing service mapping and never implies inventory', function (): void {
    $this->fixture->product->update(['product_type' => 'service', 'track_inventory' => false]);
    $mapping = new class implements ServiceItemPolicy
    {
        public function mapping(int $tenantId, int $siteId, int $variantId): ?FixedPrintingMapping
        {
            return new FixedPrintingMapping('fictional_fixed_printing_review_v1');
        }
    };
    $catalog = new WebsitePaymentCatalog(DB::connection(), $mapping);
    $line = $catalog->item($this->binding->tenantId, $this->fixture->site->id, $this->fixture->variant->id);
    expect($line->kind)->toBe('printing_service')->and($line->inventoryTracked)->toBeFalse()->and($line->availableQuantity)->toBeNull();
    $this->fixture->product->update(['product_type' => 'quote']);
    expect(fn () => $catalog->item($this->binding->tenantId, $this->fixture->site->id, $this->fixture->variant->id))
        ->toThrow(QuoteException::class, 'catalog_kind_unsupported');
});

it('rejects inactive products unavailable variants zero prices and excessive integer totals', function (string $change): void {
    match ($change) {
        'draft' => $this->fixture->product->update(['status' => 'draft']),
        'unavailable' => $this->fixture->variant->update(['is_available' => false]),
        'zero price' => $this->fixture->variant->update(['price_cents' => 0]),
        'too large' => $this->fixture->variant->update(['price_cents' => 100000000]),
    };
    expect(fn () => ($this->selection)(2))->toThrow(QuoteException::class);
})->with(['draft', 'unavailable', 'zero price', 'too large']);

it('lists bounded canonical revisions and treats exact duplicated SKU as ambiguous', function (): void {
    $listing = $this->catalog->listing($this->binding->tenantId, $this->fixture->site->id);
    expect($listing['items'])->toHaveCount(1)->and($listing['items'][0]['expected_revision'])->toHaveLength(64)
        ->and($listing['items'][0])->not->toHaveKey('tax_amount_minor');
    expect($this->catalog->listing($this->binding->tenantId, $this->fixture->site->id, null, 25, 'sim-kit')['items'])->toBe([]);
    $product = $this->fixture->product->replicate();
    $product->handle = 'ambiguous-second-product';
    $product->save();
    $copy = $this->fixture->variant->replicate();
    $copy->website_product_id = $product->id;
    $copy->save();
    expect(fn () => $this->catalog->listing($this->binding->tenantId, $this->fixture->site->id, null, 25, 'SIM-KIT'))
        ->toThrow(QuoteException::class, 'catalog_sku_ambiguous');
    expect(fn () => $this->catalog->listing($this->binding->tenantId, $this->fixture->site->id, '1', 25, 'SIM-KIT'))
        ->toThrow(QuoteException::class, 'invalid_catalog_query');
});

it('detects altered encrypted quote or mismatched plain index metadata without exposing evidence', function (string $column): void {
    $quote = ($this->issue)();
    DB::table('payment_core_quotes')->where('quote_id', $quote->id)->update([$column => $column === 'snapshot' ? 'tampered' : str_repeat('0', 64)]);
    expect(fn () => $this->repository->findById($this->binding->tenantId, $quote->id))->toThrow(QuoteException::class, 'quote_evidence_invalid');
})->with(['snapshot', 'selection_hash', 'binding_fingerprint', 'scope_hash', 'payload_hash']);
