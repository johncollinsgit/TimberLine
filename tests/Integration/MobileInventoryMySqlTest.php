<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\PaymentsCore\Inventory\InventoryReservationAuthority;
use App\Services\PaymentsCore\Inventory\InventoryUnavailableException;
use App\Services\PaymentsCore\Inventory\WebsitePaymentInventory;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\Mobile\MobilePaymentAccess;
use App\Services\PaymentsCore\Mobile\MobilePaymentService;
use App\Services\PaymentsCore\Mobile\MobileRegisterDirectory;
use App\Services\PaymentsCore\MySqlPaymentJournal;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\PaymentQuoteService;
use App\Services\PaymentsCore\Quotes\TaxAssessment;
use App\Services\PaymentsCore\Quotes\TaxPolicy;
use App\Services\PaymentsCore\Quotes\TrustedQuote;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use App\Services\PaymentsCore\Simulation\FileSimulationStore;
use App\Services\PaymentsCore\Simulation\SimulatedPaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PaymentsCore\MySqlFixture;

require_once __DIR__.'/../Support/PaymentsCore/MySqlFixture.php';

uses(Tests\TestCase::class);

/** A second DB connection observes committed intent/hold before the fictional effect. */
final class MobileInventoryCommitProbe implements PaymentProvider
{
    public function __construct(private SimulatedPaymentProvider $provider) {}

    public function supports(MerchantBinding $binding): bool
    {
        return $this->provider->supports($binding);
    }

    public function createPayment(array $row): ProviderEvidence
    {
        $this->committed($row);
        $seen = DB::connection('mobile_inventory_probe')->table('website_inventory_reservations')
            ->where('payment_core_operation_id', $row['id'])->where('status', 'active')->sum('quantity');
        if ((int) $seen !== 2) {
            throw new RuntimeException('Fictional dispatch could not observe the committed inventory hold.');
        }

        return $this->provider->createPayment($row);
    }

    public function createRefund(array $row): ProviderEvidence
    {
        $this->committed($row);

        return $this->provider->createRefund($row);
    }

    public function findPayment(array $row): ?ProviderEvidence
    {
        $this->outsideTransaction();

        return $this->provider->findPayment($row);
    }

    public function findRefund(array $row): ?ProviderEvidence
    {
        $this->outsideTransaction();

        return $this->provider->findRefund($row);
    }

    private function committed(array $row): void
    {
        $this->outsideTransaction();
        if (! DB::connection('mobile_inventory_probe')->table('payment_core_operations')->where('id', $row['id'])->where('claimed', true)->exists()) {
            throw new RuntimeException('Fictional dispatch could not observe its committed intent.');
        }
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection('mobile_inventory_probe')->transactionLevel() !== 0) {
            throw new RuntimeException('A provider operation ran inside a database transaction.');
        }
    }
}

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly opted-in local disposable MySQL.');
    }
    MySqlFixture::guard();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional inventory HTTP test', 'slug' => 'inventory-http-'.Str::lower(Str::random(12))]);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $this->site = TenantSite::create(['tenant_id' => $this->tenant->id, 'subdomain' => $this->tenant->slug, 'status' => 'draft', 'settings' => []]);
    $product = WebsiteProduct::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'handle' => 'fictional-retail-kit', 'title' => 'Fictional retail kit', 'product_type' => 'physical', 'status' => 'active', 'track_inventory' => true]);
    $this->variant = WebsiteProductVariant::create(['tenant_id' => $this->tenant->id, 'website_product_id' => $product->id,
        'title' => 'Fictional kit', 'sku' => 'FICTIONAL-RETAIL-KIT', 'price_cents' => 1250, 'inventory_quantity' => 5, 'is_available' => true]);
    config()->set(['evergrove_payments.read_enabled' => true, 'evergrove_payments.fixture_mode' => true,
        'evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true,
        'evergrove_payments.servicing_enabled' => true,
        'evergrove_payments.registers.fixture_register' => ['tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'name' => 'Fictional inventory register', 'active' => true, 'user_ids' => [$this->actor->id],
            'binding' => ['provider' => 'fixture_inventory', 'merchant_account' => 'fictional_inventory_merchant',
                'location_id' => 'fictional_counter', 'environment' => 'test']]]);
    config(['database.connections.mobile_inventory_probe' => DB::connection()->getConfig()]);
    DB::purge('mobile_inventory_probe');
    $this->withToken($this->actor->createToken('Fictional inventory HTTP test', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'])->plainTextToken);
    $this->path = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/payments/registers/fixture_register';
    $this->fixtureDirectory = sys_get_temp_dir().'/mobile-inventory-http-'.Str::uuid();
    $this->outcome = 'succeeded';
    $this->loseResponse = true;
    $this->taxRevision = 'fictional_fixture_v1';
    $this->reserveChecks = 0;
    $fixture = $this;
    app()->bind(MobilePaymentService::class, function ($app) use ($fixture): MobilePaymentService {
        $directory = new MobileRegisterDirectory;
        $access = new MobilePaymentAccess($app['request'], $directory);
        $catalog = new WebsitePaymentCatalog(DB::connection());
        $tax = new class($fixture) implements TaxPolicy
        {
            public function __construct(private object $fixture) {}

            public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment
            {
                return new TaxAssessment('fictional_test_only', $this->fixture->taxRevision, TaxAssessment::scope($binding, $siteId),
                    TaxAssessment::terms($lines), 'usd', 'exclusive', 'per_line_half_up',
                    array_fill_keys(array_map(fn ($line) => $line->lineId, $lines), 100), $at->modify('-1 day'), $at->modify('+1 day'));
            }
        };
        $quotes = new PaymentQuoteService($catalog, new DatabaseQuoteRepository(DB::connection(), $app['encrypter']), $tax);
        $authority = new class($access, $quotes, $fixture) implements InventoryReservationAuthority
        {
            public function __construct(private MobilePaymentAccess $access, private PaymentQuoteService $quotes, private object $fixture) {}

            public function assertAllowed(TrustedQuote $quote, string $operation): void
            {
                if (! app()->environment('testing') || $quote->binding->environment !== 'test' || $quote->binding->provider !== 'fixture_inventory') {
                    throw new InventoryUnavailableException('Fictional inventory authority denied.');
                }
                try {
                    $this->access->assertAllowed($quote->binding, $operation === 'reserve' ? 'collect' : 'recover');
                    if ($operation === 'reserve') {
                        $this->fixture->reserveChecks++;
                        if (isset($this->fixture->beforeReserve) && $this->fixture->reserveChecks === 2) {
                            ($this->fixture->beforeReserve)();
                            unset($this->fixture->beforeReserve);
                        }
                        $this->quotes->assertCurrentTaxPolicy($quote);
                    }
                } catch (\App\Services\PaymentsCore\Mobile\MobilePaymentException|\App\Services\PaymentsCore\Quotes\QuoteException $error) {
                    throw new InventoryUnavailableException('Fictional inventory authority revoked.', previous: $error);
                }
            }
        };
        $inventory = new WebsitePaymentInventory(DB::connection(), $catalog, $app['encrypter'], $authority);
        $journal = new MySqlPaymentJournal(DB::connection(), $app['encrypter'], $inventory);
        $provider = $fixture->prebuiltProvider ?? new MobileInventoryCommitProbe(new SimulatedPaymentProvider(new FileSimulationStore($fixture->fixtureDirectory), $fixture->outcome, $fixture->loseResponse));

        return new MobilePaymentService(new PaymentCore($journal, ['fixture_inventory' => $provider], $access),
            $journal, $quotes, $catalog, $access, $directory);
    });
    $this->issueQuote = function (): array {
        $catalog = $this->getJson($this->path.'/catalog')->assertOk()->json('items.0');

        return $this->postJson($this->path.'/quotes', ['request_id' => (string) Str::uuid(), 'items' => [
            ['variant_id' => $catalog['variant_id'], 'quantity' => 2, 'expected_revision' => $catalog['expected_revision']],
        ]])->assertOk()->assertJsonPath('quote.amount_minor', 2600)->json('quote');
    };
});

afterEach(function (): void {
    if (isset($this->tenant)) {
        // Fixtures belong exclusively to this newly created tenant, without an outer transaction.
        $tenant = $this->tenant->id;
        $ids = DB::table('payment_core_operations')->where('tenant_id', $tenant)->pluck('id');
        DB::table('website_inventory_movements')->where('tenant_id', $tenant)->delete();
        DB::table('website_inventory_reservations')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_events')->whereIn('operation_id', $ids)->delete();
        DB::table('payment_core_operations')->where('tenant_id', $tenant)->where('kind', 'refund')->delete();
        DB::table('payment_core_operations')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_bindings')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_admissions')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_quotes')->where('tenant_id', $tenant)->delete();
        $this->actor->tokens()->delete();
        $this->tenant->delete();
        $this->actor->delete();
        File::deleteDirectory($this->fixtureDirectory);
        DB::purge('mobile_inventory_probe');
    }
    Http::assertNothingSent();
});

it('holds unknown stock, replays frozen identity and reconciles once before a no-restock refund over authenticated HTTP', function (): void {
    $shared = json_decode(file_get_contents(base_path('tests/PaymentsCore/fixtures/mobile-inventory-checkout.json')), true, 32, JSON_THROW_ON_ERROR);
    $quote = ($this->issueQuote)();
    $input = ['request_id' => strtoupper((string) Str::uuid()), 'quote_id' => strtoupper($quote['quote_id']), 'quote_version' => 1];
    $attempt = $this->postJson($this->path.'/attempts', $input)->assertOk()->assertJsonPath('mode', 'fixture')
        ->assertJsonPath('attempt.state', 'unknown')->assertJsonPath('attempt.amount_minor', $shared['quote_response']['quote']['amount_minor'])->json('attempt');
    $row = DB::table('payment_core_operations')->where('id', $attempt['attempt_id'])->first();
    expect($row->inventory_state)->toBe('held')->and($this->variant->fresh()->inventory_quantity)->toBe(5);
    $hold = WebsiteInventoryReservation::where('payment_core_operation_id', $attempt['attempt_id'])->sole();
    expect($hold->quantity)->toBe(2)->and($hold->expires_at)->toBeNull()->and($hold->status)->toBe('active');
    $events = DB::table('payment_core_events')->where('operation_id', $attempt['attempt_id'])->count();
    $this->getJson($this->path.'/attempts/'.$attempt['attempt_id'])->assertOk()->assertJsonPath('attempt.state', 'unknown')
        ->assertJsonMissingPath('attempt.inventory_snapshot')->assertJsonMissingPath('attempt.binding')->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('payment_core_events')->where('operation_id', $attempt['attempt_id'])->count())->toBe($events);
    $this->variant->update(['price_cents' => 9999]);
    $this->travel(6)->minutes();
    config()->set('evergrove_payments.collection_enabled', false);
    $this->postJson($this->path.'/attempts', $input)->assertOk()->assertJsonPath('attempt.attempt_id', $attempt['attempt_id']);
    expect(DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    $this->loseResponse = false; // A new adapter instance reopens the same private fictional ledger.
    $statusPath = $this->path.'/attempts/'.$attempt['attempt_id'];
    $this->postJson($statusPath.'/reconcile', [])->assertOk()->assertJsonPath('attempt.state', 'succeeded')->assertJsonPath('attempt.canonical_confirmation', true);
    $this->postJson($statusPath.'/reconcile', [])->assertOk();
    expect($this->variant->fresh()->inventory_quantity)->toBe(3)
        ->and((int) DB::table('website_inventory_movements')->where('tenant_id', $this->tenant->id)->sum('quantity_delta'))->toBe(-2)
        ->and(DB::table('payment_core_operations')->where('id', $attempt['attempt_id'])->value('inventory_state'))->toBe('committed');
    $refund = ['request_id' => strtoupper((string) Str::uuid()), 'amount_minor' => 600, 'reason' => 'requested_by_customer'];
    $refundAttempt = $this->postJson($statusPath.'/refunds', $refund)->assertOk()->assertJsonPath('attempt.state', 'succeeded')->json('attempt');
    $this->postJson($statusPath.'/refunds', $refund)->assertOk()->assertJsonPath('attempt.attempt_id', $refundAttempt['attempt_id']);
    $refund['amount_minor'] = 601;
    $this->postJson($statusPath.'/refunds', $refund)->assertConflict();
    expect($this->variant->fresh()->inventory_quantity)->toBe(3)
        ->and(DB::table('website_inventory_movements')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('releases the retained unknown hold only after canonical failure or cancellation', function (string $outcome): void {
    $this->outcome = $outcome;
    $quote = ($this->issueQuote)();
    $attempt = $this->postJson($this->path.'/attempts', ['request_id' => (string) Str::uuid(), 'quote_id' => $quote['quote_id'], 'quote_version' => 1])
        ->assertOk()->assertJsonPath('attempt.state', 'unknown')->json('attempt');
    $this->loseResponse = false;
    $this->postJson($this->path.'/attempts/'.$attempt['attempt_id'].'/reconcile', [])->assertOk()->assertJsonPath('attempt.state', $outcome);
    expect($this->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryReservation::where('payment_core_operation_id', $attempt['attempt_id'])->sole()->status)->toBe('released')
        ->and(DB::table('website_inventory_movements')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
})->with(['failed', 'cancelled']);

it('honors a competing Website hold added after quote creation without creating an intent or fictional effect', function (): void {
    $quote = ($this->issueQuote)();
    $order = WebsiteOrder::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'number' => 'FIXTURE-'.Str::random(10),
        'lookup_token' => Str::random(56), 'source' => 'native', 'currency' => 'usd', 'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled', 'subtotal_cents' => 1000, 'total_cents' => 1000]);
    WebsiteInventoryReservation::create(['tenant_id' => $this->tenant->id, 'website_order_id' => $order->id,
        'website_product_variant_id' => $this->variant->id, 'quantity' => 4, 'status' => 'active', 'expires_at' => now()->addHour()]);
    $this->postJson($this->path.'/attempts', ['request_id' => (string) Str::uuid(), 'quote_id' => $quote['quote_id'], 'quote_version' => 1])->assertConflict();
    expect(DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($this->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(is_file($this->fixtureDirectory.'/fixture.json'))->toBeFalse();
});

it('keeps production collection closed even with an inventory participant and every fixture flag injected', function (): void {
    $quote = ($this->issueQuote)();
    $this->prebuiltProvider = new MobileInventoryCommitProbe(new SimulatedPaymentProvider(new FileSimulationStore($this->fixtureDirectory)));
    app()->instance('env', 'production');
    try {
        expect(fn () => new FileSimulationStore($this->fixtureDirectory))->toThrow(DomainException::class);
        $this->postJson($this->path.'/attempts', ['request_id' => (string) Str::uuid(), 'quote_id' => $quote['quote_id'], 'quote_version' => 1])
            ->assertStatus(503)->assertJsonPath('error.code', 'stock_commit_unavailable')->assertJsonPath('mode', 'unavailable');
    } finally {
        app()->instance('env', 'testing');
    }
    expect(DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('rechecks the current local tax policy after preflight inside the journal transaction', function (): void {
    $quote = ($this->issueQuote)();
    $fixture = $this;
    $this->beforeReserve = function () use ($fixture): void {
        $fixture->taxRevision = 'fictional_changed_policy';
    };
    $this->postJson($this->path.'/attempts', ['request_id' => (string) Str::uuid(), 'quote_id' => $quote['quote_id'], 'quote_version' => 1])
        ->assertStatus(503)->assertJsonPath('error.code', 'stock_commit_unavailable');
    expect($this->reserveChecks)->toBe(2)
        ->and(DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(DB::table('website_inventory_reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(is_file($this->fixtureDirectory.'/fixture.json'))->toBeFalse();
});
