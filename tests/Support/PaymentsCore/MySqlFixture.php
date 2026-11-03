<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsCore;

use App\Models\Tenant;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\MySqlPaymentJournal;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\SaleSnapshot;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Fictional local MySQL fixtures only. No .env or production provider access. */
final class MySqlFixture
{
    public static function guard(): void
    {
        $db = DB::connection();
        if (getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1' || ! app()->environment('testing')
            || $db->getDriverName() !== 'mysql'
            || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
            || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('Refusing payment fixtures outside explicitly opted-in local disposable MySQL.');
        }
        Http::preventStrayRequests();
    }

    public static function tenant(): Tenant
    {
        self::guard();

        return Tenant::create(['name' => 'Fictional payment journal fixture', 'slug' => 'payment-test-'.Str::lower(Str::random(14))]);
    }

    public static function binding(int $tenant, string $register = 'fixture_register', string $merchant = 'alpha_fixture'): MerchantBinding
    {
        return new MerchantBinding($tenant, $register, 'fixture_provider', $merchant, 'fixture_location', 'test');
    }

    public static function sale(string $sale = 'fixture_sale', int $price = 1000): SaleSnapshot
    {
        return new SaleSnapshot($sale, 'usd', [['line_id' => 'fixture_line', 'kind' => 'printing_service',
            'quantity' => 1, 'unit_amount_minor' => $price, 'tax_amount_minor' => 70]]);
    }

    public static function journal(): MySqlPaymentJournal
    {
        self::guard();

        return new MySqlPaymentJournal(DB::connection(), app('encrypter'));
    }

    public static function core(int $tenant, ?DurableFixtureProvider $provider = null): PaymentCore
    {
        return new PaymentCore(self::journal(), ['fixture_provider' => $provider ?? new DurableFixtureProvider], new FixtureAccess($tenant));
    }

    public static function purgeTenant(int $tenant): void
    {
        self::guard();
        $ids = DB::table('payment_core_operations')->where('tenant_id', $tenant)->pluck('id');
        DB::table('payment_core_events')->whereIn('operation_id', $ids)->delete();
        DB::table('payment_core_operations')->where('tenant_id', $tenant)->where('kind', 'refund')->delete();
        DB::table('payment_core_operations')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_bindings')->where('tenant_id', $tenant)->delete();
        DB::table('payment_core_admissions')->where('tenant_id', $tenant)->delete();
        if (DB::getSchemaBuilder()->hasTable('pc_fixture_evidence')) {
            DB::table('pc_fixture_evidence')->where('tenant_id', $tenant)->delete();
            DB::table('pc_fixture_calls')->where('tenant_id', $tenant)->delete();
        }
        Tenant::find($tenant)?->delete();
    }
}

final class FixtureAccess implements PaymentAccess
{
    public function __construct(private int $tenant) {}

    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        if ($binding->tenantId !== $this->tenant || $binding->environment !== 'test'
            || ! in_array($operation, ['collect', 'inspect', 'recover', 'refund'], true)) {
            throw new DomainException('Fixture access denied.');
        }
    }
}

/** Independent connection and canonical evidence table prove pre-HTTP commits. */
final class DurableFixtureProvider implements PaymentProvider
{
    public string $nextState = 'succeeded';

    public bool $loseResponse = false;

    public ?string $fixedEffect = null;

    private Connection $db;

    public function __construct()
    {
        MySqlFixture::guard();
        config(['database.connections.payment_fixture_provider' => DB::connection()->getConfig()]);
        $this->db = DB::connection('payment_fixture_provider');
        $this->db->statement('CREATE TABLE IF NOT EXISTS pc_fixture_evidence (
            tenant_id BIGINT UNSIGNED NOT NULL, request_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
            evidence TEXT NOT NULL, PRIMARY KEY(tenant_id, request_id))');
        $this->db->statement('CREATE TABLE IF NOT EXISTS pc_fixture_calls (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL,
            request_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL)');
    }

    public function supports(MerchantBinding $binding): bool
    {
        return $binding->environment === 'test' && $binding->provider === 'fixture_provider';
    }

    public function createPayment(array $reservedAttempt): ProviderEvidence
    {
        return $this->create($reservedAttempt);
    }

    public function createRefund(array $reservedRefund): ProviderEvidence
    {
        return $this->create($reservedRefund);
    }

    public function findPayment(array $reservedAttempt): ?ProviderEvidence
    {
        return $this->find($reservedAttempt);
    }

    public function findRefund(array $reservedRefund): ?ProviderEvidence
    {
        return $this->find($reservedRefund);
    }

    public function calls(int $tenant): int
    {
        return $this->db->table('pc_fixture_calls')->where('tenant_id', $tenant)->count();
    }

    public function evidence(array $row, string $state = 'succeeded', int $version = 1): ProviderEvidence
    {
        return new ProviderEvidence($row['request_id'], MerchantBinding::restore($row['binding'])->fingerprint(),
            $this->fixedEffect ?? 'fixture_'.hash('sha256', $row['tenant_id'].':'.$row['request_id']),
            $row['amount_minor'], $row['currency'], $state, $version, $row['original_provider_id']);
    }

    private function create(array $row): ProviderEvidence
    {
        if (DB::connection()->transactionLevel() !== 0 || $this->db->transactionLevel() !== 0
            || ! $this->db->table('payment_core_operations')->where('id', $row['id'])->where('claimed', true)->exists()) {
            throw new RuntimeException('Dispatch was not durably committed before provider operation.');
        }
        $this->db->table('pc_fixture_calls')->insert(['tenant_id' => $row['tenant_id'], 'request_id' => $row['request_id']]);
        $evidence = $this->evidence($row, $this->nextState);
        $this->db->table('pc_fixture_evidence')->insert(['tenant_id' => $row['tenant_id'], 'request_id' => $row['request_id'],
            'evidence' => json_encode((array) $evidence, JSON_THROW_ON_ERROR)]);
        if ($this->loseResponse) {
            throw new RuntimeException('Fictional response lost after canonical acceptance.');
        }

        return $evidence;
    }

    private function find(array $row): ?ProviderEvidence
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('Provider retrieval inside a caller transaction.');
        }
        $json = $this->db->table('pc_fixture_evidence')->where('tenant_id', $row['tenant_id'])
            ->where('request_id', $row['request_id'])->value('evidence');

        return $json ? new ProviderEvidence(...json_decode($json, true, 512, JSON_THROW_ON_ERROR)) : null;
    }
}
