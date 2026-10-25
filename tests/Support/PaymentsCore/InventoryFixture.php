<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsCore;

require_once __DIR__.'/MySqlFixture.php';

use App\Services\PaymentsCore\Inventory\InventoryReservationAuthority;
use App\Services\PaymentsCore\Inventory\InventoryUnavailableException;
use App\Services\PaymentsCore\Inventory\WebsitePaymentInventory;
use App\Services\PaymentsCore\MerchantBinding;
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
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PosFixture;

/** Fictional records in one explicitly opted-in local disposable MySQL database. */
final class InventoryFixture
{
    public PosFixture $pos;

    public MerchantBinding $binding;

    public WebsitePaymentCatalog $catalog;

    public InventoryFixtureAuthority $authority;

    public MySqlPaymentJournal $journal;

    public InventoryFixtureProvider $provider;

    public PaymentCore $core;

    public PaymentQuoteService $quotes;

    public static function guard(): void
    {
        MySqlFixture::guard();
        if (getenv('PAYMENTS_INVENTORY_DISPOSABLE_MYSQL') !== '1') {
            throw new \RuntimeException('Inventory fixtures require their explicit disposable MySQL opt-in.');
        }
    }

    public static function make(int $stock = 2): self
    {
        self::guard();
        $f = new self;
        $f->pos = PosFixture::merchant('inventory-'.Str::lower(Str::random(10)), $stock);
        $f->binding = MySqlFixture::binding($f->pos->tenant->id);
        $f->catalog = new WebsitePaymentCatalog(DB::connection());
        $f->authority = new InventoryFixtureAuthority($f->binding->tenantId);
        $f->journal = new MySqlPaymentJournal(DB::connection(), app('encrypter'),
            new WebsitePaymentInventory(DB::connection(), $f->catalog, app('encrypter'), $f->authority));
        $f->provider = new InventoryFixtureProvider;
        $f->core = new PaymentCore($f->journal, ['fixture_provider' => $f->provider], new FixtureAccess($f->binding->tenantId));
        $f->quotes = new PaymentQuoteService($f->catalog, new DatabaseQuoteRepository(DB::connection(), app('encrypter')), new InventoryFixtureTax);

        return $f;
    }

    public function quote(?MerchantBinding $binding = null, array $variantIds = [], int $quantity = 1): TrustedQuote
    {
        $binding ??= $this->binding;
        $items = array_map(fn ($id) => ['variant_id' => $id, 'quantity' => $quantity,
            'expected_revision' => $this->catalog->item($binding->tenantId, $this->pos->site->id, $id, $quantity)->revision], $variantIds ?: [$this->pos->variant->id]);

        return $this->quotes->createQuote($binding, $this->pos->cashier->id, $this->pos->site->id, (string) Str::uuid(), $items);
    }

    public function cleanup(): void
    {
        $tenant = $this->binding->tenantId;
        DB::table('website_inventory_movements')->where('tenant_id', $tenant)->delete();
        DB::table('website_inventory_reservations')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_quotes')->where('tenant_id', $tenant)->delete();
        DB::table('pos_registers')->where('tenant_id', $tenant)->delete();
        DB::table('website_orders')->where('tenant_id', $tenant)->delete();
        MySqlFixture::purgeTenant($tenant);
        $this->pos->cashier->delete();
        $this->pos->manager->delete();
    }
}

final class InventoryFixtureAuthority implements InventoryReservationAuthority
{
    public bool $enabled = true;

    public function __construct(private int $tenant) {}

    public function assertAllowed(TrustedQuote $quote, string $operation): void
    {
        if (! $this->enabled || ! app()->environment('testing') || $quote->binding->tenantId !== $this->tenant
            || $quote->binding->environment !== 'test' || $quote->binding->provider !== 'fixture_provider'
            || $quote->tax->authority !== 'inventory_fixture_authority' || $quote->tax->policyRevision !== 'inventory_fixture_v1'
            || ! in_array($operation, ['reserve', 'settle'], true)) {
            throw new InventoryUnavailableException('Explicit synthetic inventory authority denied.');
        }
    }
}

final class InventoryFixtureTax implements TaxPolicy
{
    public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment
    {
        return new TaxAssessment('inventory_fixture_authority', 'inventory_fixture_v1', TaxAssessment::scope($binding, $siteId),
            TaxAssessment::terms($lines), 'usd', 'exclusive', 'per_line_half_up',
            array_fill_keys(array_map(fn ($line) => $line->lineId, $lines), 0), $at->modify('-1 minute'), $at->modify('+10 minutes'));
    }
}

final class InventoryFixtureProvider implements PaymentProvider
{
    public DurableFixtureProvider $base;

    public function __construct()
    {
        $this->base = new DurableFixtureProvider;
    }

    public function supports(MerchantBinding $binding): bool
    {
        return $this->base->supports($binding);
    }

    public function createPayment(array $row): ProviderEvidence
    {
        $expected = count(array_filter($row['inventory_snapshot']['catalog_lines'], fn ($line) => $line['inventory_tracked']));
        $visible = DB::connection('payment_fixture_provider')->table('website_inventory_reservations')->where('payment_core_operation_id', $row['id'])->where('status', 'active')->count();
        if (DB::connection()->transactionLevel() !== 0 || $expected !== $visible) {
            throw new \RuntimeException('All inventory holds must be committed before fixture provider creation.');
        }

        return $this->base->createPayment($row);
    }

    public function findPayment(array $row): ?ProviderEvidence
    {
        return $this->base->findPayment($row);
    }

    public function createRefund(array $row): ProviderEvidence
    {
        return $this->base->createRefund($row);
    }

    public function findRefund(array $row): ?ProviderEvidence
    {
        return $this->base->findRefund($row);
    }
}
