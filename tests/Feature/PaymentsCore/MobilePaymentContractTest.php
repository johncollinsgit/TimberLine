<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\PaymentsCore\Inventory\QuotedPaymentJournal;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\Mobile\MobilePaymentAccess;
use App\Services\PaymentsCore\Mobile\MobilePaymentService;
use App\Services\PaymentsCore\Mobile\MobileRegisterDirectory;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\PaymentQuoteService;
use App\Services\PaymentsCore\Quotes\TaxAssessment;
use App\Services\PaymentsCore\Quotes\TaxPolicy;
use App\Services\PaymentsCore\Quotes\UnavailableTaxPolicy;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\PaymentsCore\SqlitePaymentJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PaymentsCore\QuotedRehearsalJournal;

/** Explicit synthetic provider, available only through this test's container binding. */
final class MobileContractFixtureProvider implements PaymentProvider
{
    public bool $available = true;

    public int $creates = 0;

    public int $lookups = 0;

    public int $refunds = 0;

    public function supports(MerchantBinding $binding): bool
    {
        return $this->available && $binding->environment === 'test' && $binding->provider === 'fixture_gateway';
    }

    private function evidence(array $row): ProviderEvidence
    {
        return new ProviderEvidence($row['request_id'], MerchantBinding::restore($row['binding'])->fingerprint(),
            'fixture_effect_'.$row['id'], $row['amount_minor'], $row['currency'], 'succeeded', 1, $row['original_provider_id']);
    }

    public function createPayment(array $reservedAttempt): ProviderEvidence
    {
        $this->creates++;

        return $this->evidence($reservedAttempt);
    }

    public function findPayment(array $reservedAttempt): ?ProviderEvidence
    {
        $this->lookups++;

        return $this->evidence($reservedAttempt);
    }

    public function createRefund(array $reservedRefund): ProviderEvidence
    {
        $this->refunds++;

        return $this->evidence($reservedRefund);
    }

    public function findRefund(array $reservedRefund): ?ProviderEvidence
    {
        $this->lookups++;

        return $this->evidence($reservedRefund);
    }
}

/** Simulates an authorization change while the durable claim was waiting. */
final class MobileContractClaimBarrier implements QuotedPaymentJournal
{
    public function __construct(private PaymentJournal $inner, private Closure $afterClaim) {}

    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        return $this->inner->reservePayment($binding, $sale, $requestId);
    }

    public function reserveQuotedPayment(\App\Services\PaymentsCore\Quotes\TrustedQuote $quote, string $requestId): array
    {
        return $this->inner->reserveQuotedPayment($quote, $requestId);
    }

    public function reserveRefund(int $tenantId, string $paymentId, string $requestId, int $amountMinor, string $reason): array
    {
        return $this->inner->reserveRefund($tenantId, $paymentId, $requestId, $amountMinor, $reason);
    }

    public function get(int $tenantId, string $id): array
    {
        return $this->inner->get($tenantId, $id);
    }

    public function findByRequest(int $tenantId, string $requestId): ?array
    {
        return $this->inner->findByRequest($tenantId, $requestId);
    }

    public function claim(int $tenantId, string $id): bool
    {
        $claimed = $this->inner->claim($tenantId, $id);
        ($this->afterClaim)();

        return $claimed;
    }

    public function unknown(int $tenantId, string $id): array
    {
        return $this->inner->unknown($tenantId, $id);
    }

    public function apply(int $tenantId, string $id, ProviderEvidence $evidence): array
    {
        return $this->inner->apply($tenantId, $id, $evidence);
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Synthetic payment API fixture', 'slug' => 'mobile-payment-fixture']);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $this->site = TenantSite::create(['tenant_id' => $this->tenant->id, 'subdomain' => $this->tenant->slug, 'status' => 'draft', 'settings' => []]);
    $product = WebsiteProduct::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'handle' => 'synthetic-api-kit', 'title' => 'Synthetic API kit', 'product_type' => 'physical', 'status' => 'active', 'track_inventory' => true]);
    $this->variant = WebsiteProductVariant::create(['tenant_id' => $this->tenant->id, 'website_product_id' => $product->id,
        'title' => 'Synthetic kit', 'sku' => 'FIXTURE-API', 'price_cents' => 1000, 'inventory_quantity' => 5, 'is_available' => true]);
    config()->set(['evergrove_payments.read_enabled' => true, 'evergrove_payments.fixture_mode' => true,
        'evergrove_payments.registers.fixture_register' => ['tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'name' => 'Synthetic register', 'active' => true, 'user_ids' => [$this->actor->id],
            'binding' => ['provider' => 'fixture_gateway', 'merchant_account' => 'fixture_merchant',
                'location_id' => 'fixture_location', 'environment' => 'test']]]);
    $this->token = $this->actor->createToken('Synthetic API test', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'])->plainTextToken;
    $this->withToken($this->token);
    $this->base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/payments/registers';
    $this->registerPath = $this->base.'/fixture_register';
    $reference = new SqlitePaymentJournal(new PDO('sqlite::memory:'));
    $reference->installForTesting();
    $this->journal = new QuotedRehearsalJournal($reference);
    $this->provider = new MobileContractFixtureProvider;
    $this->tax = new UnavailableTaxPolicy;
    $fixture = $this;
    app()->bind(MobilePaymentService::class, function ($app) use ($fixture): MobilePaymentService {
        $directory = new MobileRegisterDirectory;
        $access = new MobilePaymentAccess($app['request'], $directory);
        $catalog = new WebsitePaymentCatalog(DB::connection());
        $quotes = new PaymentQuoteService($catalog, new DatabaseQuoteRepository(DB::connection(), $app['encrypter']),
            $fixture->tax, fn () => now()->toDateTimeImmutable());

        return new MobilePaymentService(new PaymentCore($fixture->journal, ['fixture_gateway' => $fixture->provider], $access),
            $fixture->journal, $quotes, $catalog, $access, $directory);
    });
    $this->quoteInput = function (): array {
        $line = (new WebsitePaymentCatalog(DB::connection()))->item($this->tenant->id, $this->site->id, $this->variant->id);

        return ['request_id' => (string) Str::uuid(), 'items' => [['variant_id' => $this->variant->id, 'quantity' => 1, 'expected_revision' => $line->revision]]];
    };
    $this->enableFixtureTax = function (): void {
        $this->tax = new class implements TaxPolicy
        {
            public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment
            {
                return new TaxAssessment('synthetic_test_only', 'fixture_v1', TaxAssessment::scope($binding, $siteId),
                    TaxAssessment::terms($lines), 'usd', 'exclusive', 'per_line_half_up',
                    array_fill_keys(array_map(fn ($line) => $line->lineId, $lines), 0), $at->modify('-1 day'), $at->modify('+1 day'));
            }
        };
    };
});

it('keeps defaults closed and returns bounded no-store register projections', function (): void {
    config()->set('evergrove_payments.read_enabled', false);
    $this->getJson($this->base)->assertStatus(503)->assertJsonPath('error.code', 'payments_unavailable');
    config()->set('evergrove_payments.read_enabled', true);
    $this->getJson($this->base)->assertOk()->assertJsonPath('mode', 'fixture')
        ->assertJsonPath('registers.0.collection_available', false)->assertJsonMissingPath('registers.0.binding')
        ->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
});

it('requires an explicit payment-scoped persisted token and current register assignment', function (): void {
    $legacy = $this->actor->createToken('legacy', ['mobile:read', 'mobile:write'])->plainTextToken;
    $this->withToken($legacy)->getJson($this->base)->assertForbidden();
    $wildcard = $this->actor->createToken('wildcard', ['*'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($wildcard)->getJson($this->base)->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($this->token);
    config()->set('evergrove_payments.registers.fixture_register.user_ids', []);
    $this->getJson($this->base)->assertOk()->assertJsonPath('registers', []);
    $this->getJson($this->registerPath.'/catalog')->assertNotFound();
});

it('does not fabricate zero tax and rejects client prices and numeric strings', function (): void {
    $input = ($this->quoteInput)();
    $this->postJson($this->registerPath.'/quotes', $input)->assertStatus(503)->assertJsonPath('error.code', 'tax_policy_missing');
    $input['items'][0]['unit_amount_minor'] = 1;
    $this->postJson($this->registerPath.'/quotes', $input)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_request');
    unset($input['items'][0]['unit_amount_minor']);
    $input['items'][0]['quantity'] = '1';
    $this->postJson($this->registerPath.'/quotes', $input)->assertUnprocessable();
    expect($this->provider->creates)->toBe(0);
    Http::assertNothingSent();
});

it('hard blocks production collection even with fixture flags and provider injected', function (): void {
    ($this->enableFixtureTax)();
    $quote = $this->postJson($this->registerPath.'/quotes', ($this->quoteInput)())->assertOk()->json('quote');
    config()->set(['evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true]);
    app()->instance('env', 'production');
    $this->postJson($this->registerPath.'/attempts', ['request_id' => (string) Str::uuid(),
        'quote_id' => $quote['quote_id'], 'quote_version' => 1])->assertStatus(503)
        ->assertJsonPath('error.code', 'stock_commit_unavailable')->assertJsonPath('mode', 'unavailable');
    expect($this->provider->creates)->toBe(0)->and($this->variant->fresh()->inventory_quantity)->toBe(5);
});

it('replays the exact frozen request after expiry or repricing and safely inspects without provider calls', function (): void {
    ($this->enableFixtureTax)();
    config()->set(['evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true]);
    $quote = $this->postJson($this->registerPath.'/quotes', ($this->quoteInput)())->assertOk()->json('quote');
    $input = ['request_id' => strtoupper((string) Str::uuid()), 'quote_id' => strtoupper($quote['quote_id']), 'quote_version' => 1];
    $attempt = $this->postJson($this->registerPath.'/attempts', $input)->assertOk()->assertJsonPath('attempt.state', 'succeeded')->json('attempt');
    $this->variant->update(['price_cents' => 9999]);
    $this->travel(6)->minutes();
    config()->set('evergrove_payments.collection_enabled', false);
    $this->postJson($this->registerPath.'/attempts', $input)->assertOk()->assertJsonPath('attempt.attempt_id', $attempt['attempt_id']);
    $this->getJson($this->registerPath.'/attempts/'.$attempt['attempt_id'])->assertOk()
        ->assertJsonMissingPath('attempt.binding')->assertJsonMissingPath('attempt.provider_id')->assertJsonMissingPath('attempt.terms');
    $input['quote_version'] = 2;
    $this->postJson($this->registerPath.'/attempts', $input)->assertConflict();
    expect($this->provider->creates)->toBe(1)->and($this->provider->lookups)->toBe(0);
});

it('services the original binding independently from collection and current merchant mapping', function (): void {
    ($this->enableFixtureTax)();
    config()->set(['evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true]);
    $quote = $this->postJson($this->registerPath.'/quotes', ($this->quoteInput)())->assertOk()->json('quote');
    $attempt = $this->postJson($this->registerPath.'/attempts', ['request_id' => (string) Str::uuid(),
        'quote_id' => $quote['quote_id'], 'quote_version' => 1])->assertOk()->json('attempt');
    config()->set(['evergrove_payments.collection_enabled' => false, 'evergrove_payments.servicing_enabled' => true,
        'evergrove_payments.registers.fixture_register.binding.provider' => 'unavailable_changed_provider',
        'evergrove_payments.registers.fixture_register.binding.merchant_account' => 'changed_merchant']);
    $refund = ['request_id' => strtoupper((string) Str::uuid()), 'amount_minor' => 400, 'reason' => 'requested_by_customer'];
    $path = $this->registerPath.'/attempts/'.$attempt['attempt_id'].'/refunds';
    $this->postJson($path, $refund)->assertOk()->assertJsonPath('attempt.state', 'succeeded');
    $this->postJson($path, $refund)->assertOk();
    $refund['amount_minor'] = 401;
    $this->postJson($path, $refund)->assertConflict();
    expect($this->provider->creates)->toBe(1)->and($this->provider->refunds)->toBe(1);
    Http::assertNothingSent();
});

it('rechecks current authority after the journal claim before provider dispatch', function (string $revocation): void {
    ($this->enableFixtureTax)();
    config()->set(['evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true]);
    $quote = $this->postJson($this->registerPath.'/quotes', ($this->quoteInput)())->assertOk()->json('quote');
    $fixture = $this;
    $this->journal = new MobileContractClaimBarrier($this->journal, function () use ($fixture, $revocation): void {
        match ($revocation) {
            'membership' => DB::table('tenant_user')->where('tenant_id', $fixture->tenant->id)->where('user_id', $fixture->actor->id)->update(['membership_active' => false]),
            'actor' => $fixture->actor->update(['is_active' => false]),
            'assignment' => config()->set('evergrove_payments.registers.fixture_register.user_ids', []),
            'token' => $fixture->actor->tokens()->delete(),
        };
    });
    $response = $this->postJson($this->registerPath.'/attempts', ['request_id' => (string) Str::uuid(),
        'quote_id' => $quote['quote_id'], 'quote_version' => 1]);
    $response->assertStatus($revocation === 'assignment' ? 404 : 403);
    expect($this->provider->creates)->toBe(0);
    Http::assertNothingSent();
})->with(['membership', 'actor', 'assignment', 'token']);

it('bounds the total and returns a fixed error instead of accepting client authority', function (): void {
    ($this->enableFixtureTax)();
    $this->variant->update(['price_cents' => 100000000]);
    $input = ($this->quoteInput)();
    $input['items'][0]['quantity'] = 2;
    $this->postJson($this->registerPath.'/quotes', $input)->assertUnprocessable()->assertJsonPath('error.code', 'catalog_price_unavailable');
    expect($this->provider->creates)->toBe(0);
});

it('returns a stable unavailable response when the original provider cannot service the payment', function (): void {
    ($this->enableFixtureTax)();
    config()->set(['evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true]);
    $quote = $this->postJson($this->registerPath.'/quotes', ($this->quoteInput)())->assertOk()->json('quote');
    $attempt = $this->postJson($this->registerPath.'/attempts', ['request_id' => (string) Str::uuid(),
        'quote_id' => $quote['quote_id'], 'quote_version' => 1])->assertOk()->json('attempt');
    config()->set('evergrove_payments.servicing_enabled', true);
    $this->provider->available = false;
    $path = $this->registerPath.'/attempts/'.$attempt['attempt_id'];
    $this->getJson($path)->assertOk();
    $this->postJson($path.'/reconcile', [])->assertStatus(503)->assertJsonPath('error.code', 'original_provider_unavailable');
    $this->postJson($path.'/refunds', ['request_id' => (string) Str::uuid(), 'amount_minor' => 100,
        'reason' => 'requested_by_customer'])->assertStatus(503)->assertJsonPath('error.code', 'original_provider_unavailable');
    expect($this->provider->refunds)->toBe(0)->and($this->provider->lookups)->toBe(0);
});
