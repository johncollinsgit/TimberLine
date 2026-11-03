<?php

declare(strict_types=1);

// Independent no-DDL synthetic checks. Never read an application .env file.
$root = dirname(__DIR__, 2);
if (getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1' || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Explicit disposable testing environment is required.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
// Use the standard console bootstrappers except LoadEnvironmentVariables.
// Every DB/app setting comes from the explicit process environment, never .env.
$app->bootstrapWith([
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    Illuminate\Foundation\Bootstrap\HandleExceptions::class,
    Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    Illuminate\Foundation\Bootstrap\SetRequestForConsole::class,
    Illuminate\Foundation\Bootstrap\RegisterProviders::class,
    Illuminate\Foundation\Bootstrap\BootProviders::class,
]);

use App\Models\Tenant;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\MySqlPaymentJournal;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\SaleSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$db = DB::connection();
if ($db->getDriverName() !== 'mysql' || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)
    || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())) {
    throw new RuntimeException('Refusing a nonlocal or non-test database.');
}
Http::preventStrayRequests();
$journal = new MySqlPaymentJournal($db, app('encrypter'));
$tenants = [];
$results = [];
function securityMysqlAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function securityMysqlDenied(callable $operation): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Expected immutable-context denial.');
}
function securityMysqlCase(string $name, callable $test): void
{
    global $results;
    try {
        $test();
        $results[] = ['case' => $name, 'result' => 'PASS'];
    } catch (Throwable $error) {
        $results[] = ['case' => $name, 'result' => 'FAIL', 'reason' => $error->getMessage()];
    }
}
function securityMysqlTenant(): Tenant
{
    global $tenants;
    $tenant = Tenant::create(['name' => 'Independent synthetic journal review', 'slug' => 'independent-security-test-'.bin2hex(random_bytes(8))]);
    $tenants[] = $tenant->id;

    return $tenant;
}
function securityMysqlReservation(string $key): array
{
    global $journal;
    $tenant = securityMysqlTenant();
    $binding = new MerchantBinding($tenant->id, 'independent_counter', 'independent_provider', 'independent_merchant', 'independent_location', 'test');
    $sale = new SaleSnapshot('sale_'.$key, 'usd', [['line_id' => 'independent_line', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 5000, 'tax_amount_minor' => 0]]);

    return [$binding, $journal->reservePayment($binding, $sale, $key)];
}
function securityMysqlEvidence(MerchantBinding $binding, array $row, string $provider = 'independent_charge'): ProviderEvidence
{
    return new ProviderEvidence($row['request_id'], $binding->fingerprint(), $provider, 5000, 'usd', 'succeeded', 1);
}

try {
    securityMysqlCase('MySQL 8.4 and REPEATABLE READ are actually exercised', function () use ($db): void {
        $version = (string) $db->selectOne('SELECT VERSION() AS v')->v;
        $isolation = strtoupper((string) $db->selectOne('SELECT @@transaction_isolation AS v')->v);
        securityMysqlAssert(str_starts_with($version, '8.4.'), 'Expected MySQL 8.4, got '.$version);
        securityMysqlAssert($isolation === 'REPEATABLE-READ', 'Expected REPEATABLE READ.');
    });
    securityMysqlCase('routing and terms persist encrypted while decoded evidence stays identical', function () use ($journal, $db): void {
        [$binding, $row] = securityMysqlReservation('ciphertext_key');
        $stored = $db->table('payment_core_operations')->where('id', $row['id'])->first();
        securityMysqlAssert(! str_contains($stored->binding, 'independent_merchant') && ! str_contains($stored->terms, 'independent_line'), 'Immutable snapshots are plaintext.');
        securityMysqlAssert($journal->get($binding->tenantId, $row['id'])['terms'] === $row['terms'], 'Encrypted evidence did not restore.');
    });
    securityMysqlCase('scalar payment amount currency and sale cannot diverge from immutable terms', function () use ($journal, $db): void {
        [$binding,$row] = securityMysqlReservation('scalar_key');
        foreach (['amount_minor' => 1, 'currency' => 'eur', 'sale_id' => 'foreign_sale'] as $field => $value) {
            $db->table('payment_core_operations')->where('id', $row['id'])->update([$field => $value]);
            try {
                securityMysqlDenied(fn () => $journal->get($binding->tenantId, $row['id']));
            } finally {
                $db->table('payment_core_operations')->where('id', $row['id'])->update([$field => $row[$field]]);
            }
        }
    });
    securityMysqlCase('registered merchant fingerprint tampering fails closed', function () use ($journal, $db): void {
        [$binding,$row] = securityMysqlReservation('binding_key');
        $stored = $db->table('payment_core_bindings')->where('id', $row['binding_id'])->first();
        $db->table('payment_core_bindings')->where('id', $row['binding_id'])->update(['fingerprint' => str_repeat('0', 64)]);
        try {
            securityMysqlDenied(fn () => $journal->get($binding->tenantId, $row['id']));
        } finally {
            $db->table('payment_core_bindings')->where('id', $row['binding_id'])->update(['fingerprint' => $stored->fingerprint]);
        }
    });
    securityMysqlCase('refund budget refuses a scalar-corrupted pending reservation', function () use ($journal, $db): void {
        [$binding,$row] = securityMysqlReservation('refund_payment_key');
        $journal->claim($binding->tenantId, $row['id']);
        $journal->apply($binding->tenantId, $row['id'], securityMysqlEvidence($binding, $row, 'refund_original_charge'));
        $refund = $journal->reserveRefund($binding->tenantId, $row['id'], 'pending_refund_key', 3000, 'other');
        $db->table('payment_core_operations')->where('id', $refund['id'])->update(['amount_minor' => 1]);
        try {
            securityMysqlDenied(fn () => $journal->reserveRefund($binding->tenantId, $row['id'], 'excess_refund_key', 2501, 'other'));
        } finally {
            $db->table('payment_core_operations')->where('id', $refund['id'])->update(['amount_minor' => 3000]);
        }
        securityMysqlAssert($journal->findByRequest($binding->tenantId, 'excess_refund_key') === null, 'Corrupted budget reserved another refund.');
    });
    securityMysqlCase('canonical provider effect cannot credit a different tenant', function () use ($journal): void {
        [$firstBinding,$first] = securityMysqlReservation('effect_first_key');
        [$secondBinding,$second] = securityMysqlReservation('effect_second_key');
        $journal->claim($firstBinding->tenantId, $first['id']);
        $journal->claim($secondBinding->tenantId, $second['id']);
        $journal->apply($firstBinding->tenantId, $first['id'], securityMysqlEvidence($firstBinding, $first, 'shared_effect'));
        $result = $journal->apply($secondBinding->tenantId, $second['id'], securityMysqlEvidence($secondBinding, $second, 'shared_effect'));
        securityMysqlAssert($result['state'] === 'review', 'One provider effect credited two tenants.');
    });
    securityMysqlCase('outer raw transaction blocks recovery before provider lookup', function () use ($journal, $db): void {
        [$binding,$row] = securityMysqlReservation('outer_transaction_key');
        $journal->claim($binding->tenantId, $row['id']);
        $provider = new class implements PaymentProvider
        {
            public int $calls = 0;

            public function supports(MerchantBinding $binding): bool
            {
                return true;
            }

            public function createPayment(array $row): ProviderEvidence
            {
                throw new RuntimeException('No collection.');
            }

            public function findPayment(array $row): ?ProviderEvidence
            {
                $this->calls++;

                return null;
            }

            public function createRefund(array $row): ProviderEvidence
            {
                throw new RuntimeException('No refund.');
            }

            public function findRefund(array $row): ?ProviderEvidence
            {
                $this->calls++;

                return null;
            }
        };
        $access = new class implements PaymentAccess
        {
            public function assertAllowed(MerchantBinding $binding, string $operation): void {}
        };
        $core = new PaymentCore($journal, ['independent_provider' => $provider], $access);
        $db->getPdo()->beginTransaction();
        try {
            securityMysqlDenied(fn () => $core->recover($binding, $row['id']));
            securityMysqlAssert($provider->calls === 0, 'Lookup happened inside raw transaction.');
        } finally {
            $db->getPdo()->rollBack();
        }
    });
} finally {
    foreach ($tenants as $tenant) {
        $ids = $db->table('payment_core_operations')->where('tenant_id', $tenant)->pluck('id');
        $db->table('payment_core_events')->whereIn('operation_id', $ids)->delete();
        $db->table('payment_core_operations')->where('tenant_id', $tenant)->where('kind', 'refund')->delete();
        $db->table('payment_core_operations')->where('tenant_id', $tenant)->delete();
        $db->table('payment_core_bindings')->where('tenant_id', $tenant)->delete();
        $db->table('payment_core_admissions')->where('tenant_id', $tenant)->delete();
        Tenant::find($tenant)?->delete();
    }
}
Http::assertNothingSent();
$failed = count(array_filter($results, fn ($r) => $r['result'] === 'FAIL'));
echo json_encode(['synthetic_only' => true, 'schema_mutations' => false, 'cases' => $results, 'passed' => count($results) - $failed, 'total' => count($results)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed ? 1 : 0);
