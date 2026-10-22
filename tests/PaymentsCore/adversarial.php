<?php

declare(strict_types=1);

// Independent synthetic-only review. No Laravel bootstrap, network, or credentials.
$repository = $argv[1] ?? (is_dir(__DIR__.'/../../app/Services/PaymentsCore')
    ? __DIR__.'/../..' : dirname(__DIR__).'/backend');
$source = rtrim($repository, '/').'/app/Services/PaymentsCore/';
if (! is_dir($source)) {
    fwrite(STDERR, "Usage: php backend-adversarial.php [repository-root]\n");
    exit(2);
}
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'App\\Services\\PaymentsCore\\';
    if (str_starts_with($class, $prefix)) {
        require $source.substr($class, strlen($prefix)).'.php';
    }
});

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\PaymentsCore\SqlitePaymentJournal;

final class TestOnlyAccess implements PaymentAccess
{
    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        if ($binding->environment !== 'test') {
            throw new DomainException('test-only');
        }
    }
}
final class ReviewProvider implements PaymentProvider
{
    public int $creates = 0;

    public int $finds = 0;

    public string $outcome = 'succeeded';

    public ?string $fixedProviderId = null;

    public function supports(MerchantBinding $b): bool
    {
        return $b->provider === 'review_psp' && $b->environment === 'test';
    }

    private function result(array $r): ProviderEvidence
    {
        return new ProviderEvidence($r['request_id'], MerchantBinding::restore($r['binding'])->fingerprint(),
            $this->fixedProviderId ?? 'synthetic_'.$r['request_id'], $r['amount_minor'], $r['currency'],
            $this->outcome, 1, $r['original_provider_id']);
    }

    public function createPayment(array $r): ProviderEvidence
    {
        $this->creates++;
        if ($this->outcome === 'throw') {
            throw new RuntimeException('synthetic timeout');
        }

return $this->result($r);
    }

    public function findPayment(array $r): ?ProviderEvidence
    {
        $this->finds++;

        return null;
    }

    public function createRefund(array $r): ProviderEvidence
    {
        $this->creates++;

        return $this->result($r);
    }

    public function findRefund(array $r): ?ProviderEvidence
    {
        $this->finds++;

        return null;
    }
}

function binding(array $changes = []): MerchantBinding
{
    return new MerchantBinding(...array_values(array_replace(['tenantId' => 161616, 'registerId' => 'review_register', 'provider' => 'review_psp',
        'merchantAccount' => 'synthetic_alpha', 'locationId' => 'synthetic_location', 'environment' => 'test'], $changes)));
}
function sale(string $id = 'review_sale', int $amount = 100): SaleSnapshot
{
    return new SaleSnapshot($id, 'usd', [['line_id' => 'review_line', 'kind' => 'printing_service', 'quantity' => 1, 'unit_amount_minor' => $amount, 'tax_amount_minor' => 0]]);
}
function setup(): array
{
    $pdo = new PDO('sqlite::memory:');
    $journal = new SqlitePaymentJournal($pdo);
    $journal->installForTesting();
    $provider = new ReviewProvider;

    return [$journal, $provider, new PaymentCore($journal, ['review_psp' => $provider], new TestOnlyAccess)];
}
function check(bool $condition, string $message = 'assertion'): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function denied(callable $fn): void
{
    try {
        $fn();
    } catch (DomainException) {
        return;
    } throw new RuntimeException('Expected denial');
}
$results = [];
function run(string $name, callable $test): void
{
    global $results;
    try {
        $test();
        $results[] = ['case' => $name, 'result' => 'PASS'];
    } catch (Throwable $e) {
        $results[] = ['case' => $name, 'result' => 'FAIL', 'reason' => $e->getMessage()];
    }
}

run('default authorizer stops all provider dispatch', function (): void {
    [$j,$p] = setup();
    $core = new PaymentCore($j, ['review_psp' => $p]);
    denied(fn () => $core->start(binding(), sale(), 'key_1'));
    check($p->creates === 0);
});
run('reference journal refuses live bindings', function (): void {
    [$j] = setup();
    denied(fn () => $j->reservePayment(binding(['environment' => 'live']), sale(), 'key_1'));
});
run('same key altered sale conflicts without second effect', function (): void {
    [$j,$p,$core] = setup();
    $first = $core->start(binding(), sale(), 'key_1');
    denied(fn () => $core->start(binding(), sale(amount: 101), 'key_1'));
    check($p->creates === 1 && $first['state'] === 'succeeded');
});
run('every foreign binding component blocks original servicing', function (): void {
    [$j,$p,$core] = setup();
    $first = $core->start(binding(), sale(), 'key_1');
    foreach (['tenantId' => 161617, 'registerId' => 'foreign_register', 'provider' => 'foreign_provider', 'merchantAccount' => 'foreign_merchant', 'locationId' => 'foreign_location', 'environment' => 'live'] as $field => $value) {
        denied(fn () => $core->recover(binding([$field => $value]), $first['id']));
    }
    check($p->finds === 0);
});
run('unknown original operation blocks alternate sale and key', function (): void {
    [$j,$p,$core] = setup();
    $p->outcome = 'throw';
    $first = $core->start(binding(), sale(), 'key_1');
    check($first['state'] === 'unknown');
    denied(fn () => $core->start(binding(), sale('different_sale'), 'key_2'));
    $again = $core->start(binding(), sale(), 'key_1');
    check($again['id'] === $first['id'] && $p->creates === 1);
    $recovered = $core->recover(binding(), $first['id']);
    check($recovered['state'] === 'unknown' && $p->finds === 1);
});
run('unknown refund reserves budget until canonical resolution', function (): void {
    [$j,$p,$core] = setup();
    $first = $core->start(binding(), sale(), 'key_1');
    $p->outcome = 'processing';
    $refund = $core->refund(binding(), $first['id'], 'refund_1', 70, 'requested_by_customer');
    check($refund['state'] === 'processing');
    denied(fn () => $core->refund(binding(), $first['id'], 'refund_2', 31, 'requested_by_customer'));
    check($p->creates === 2);
});
run('one provider effect cannot credit two sales', function (): void {
    [$j,$p,$core] = setup();
    $p->fixedProviderId = 'same_synthetic_provider_payment';
    $first = $core->start(binding(), sale('sale_1'), 'key_1');
    $second = $core->start(binding(), sale('sale_2'), 'key_2');
    check($first['state'] === 'succeeded');
    check($second['state'] !== 'succeeded', 'Duplicate canonical payment credited two distinct sales');
});
run('strict line payload rejects card fields and unsafe money', function (): void {
    $base = ['line_id' => 'review_line', 'kind' => 'postage', 'quantity' => 1, 'unit_amount_minor' => 100, 'tax_amount_minor' => 0];
    denied(fn () => new SaleSnapshot('sale_1', 'usd', [[...$base, 'card_number' => 'synthetic_forbidden_field']]));
    foreach (['quantity' => -1, 'unit_amount_minor' => 0, 'tax_amount_minor' => -1, 'kind' => 'unknown'] as $field => $value) {
        denied(fn () => new SaleSnapshot('sale_1', 'usd', [[...$base, $field => $value]]));
    }
});
echo json_encode(['syntheticOnly' => true, 'cases' => $results, 'passed' => count(array_filter($results, fn ($r) => $r['result'] === 'PASS')), 'total' => count($results)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(count(array_filter($results, fn ($r) => $r['result'] === 'FAIL')) ? 1 : 0);
