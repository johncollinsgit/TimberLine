<?php

declare(strict_types=1);

// Synthetic-only independent domain tests: no Laravel bootstrap, env file, SDK or network.
$independentRoot = $argv[1] ?? dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($independentRoot): void {
    $prefix = 'App\\Services\\PaymentsCore\\';
    if (str_starts_with($class, $prefix)) {
        require $independentRoot.'/app/Services/PaymentsCore/'.substr($class, strlen($prefix)).'.php';
    }
});

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\PaymentsCore\SqlitePaymentJournal;

final class IndependentOperationAccess implements PaymentAccess
{
    public array $seen = [];

    public array $allowed = ['inspect', 'recover', 'refund'];

    public bool $revokeOnSecondCollect = false;

    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        $this->seen[] = $operation;
        if (! in_array($operation, $this->allowed, true)
            || ($operation === 'collect' && $this->revokeOnSecondCollect
                && count(array_filter($this->seen, fn ($v) => $v === 'collect')) > 1)) {
            throw new DomainException('Synthetic permission denied.');
        }
    }
}

final class IndependentNoNetworkProvider implements PaymentProvider
{
    public int $supportsCalls = 0;

    public int $createCalls = 0;

    public int $findCalls = 0;

    public function supports(MerchantBinding $binding): bool
    {
        $this->supportsCalls++;

        return $binding->provider === 'independent_psp' && $binding->environment === 'test';
    }

    public function createPayment(array $row): ProviderEvidence
    {
        $this->createCalls++;

        return $this->evidence($row);
    }

    public function findPayment(array $row): ?ProviderEvidence
    {
        $this->findCalls++;

        return null;
    }

    public function createRefund(array $row): ProviderEvidence
    {
        $this->createCalls++;

        return $this->evidence($row);
    }

    public function findRefund(array $row): ?ProviderEvidence
    {
        $this->findCalls++;

        return null;
    }

    private function evidence(array $row): ProviderEvidence
    {
        return new ProviderEvidence($row['request_id'], MerchantBinding::restore($row['binding'])->fingerprint(),
            'independent_'.$row['request_id'], $row['amount_minor'], $row['currency'], 'succeeded', 1, $row['original_provider_id']);
    }
}

function independentBinding(array $changes = []): MerchantBinding
{
    return new MerchantBinding(...array_values(array_replace(['tenant' => 665566, 'register' => 'independent_counter',
        'provider' => 'independent_psp', 'merchant' => 'independent_alpha', 'location' => 'independent_location', 'environment' => 'test'], $changes)));
}

function independentSale(string $id = 'independent_sale'): SaleSnapshot
{
    return new SaleSnapshot($id, 'usd', [['line_id' => 'independent_line', 'kind' => 'merchandise',
        'quantity' => 1, 'unit_amount_minor' => 1500, 'tax_amount_minor' => 0]]);
}

function independentSetup(): array
{
    $db = new PDO('sqlite::memory:');
    $journal = new SqlitePaymentJournal($db);
    $journal->installForTesting();
    $access = new IndependentOperationAccess;
    $provider = new IndependentNoNetworkProvider;

    return [$db, $journal, $access, $provider, new PaymentCore($journal, ['independent_psp' => $provider], $access)];
}

function independentAssert(bool $value, string $why): void
{
    if (! $value) {
        throw new RuntimeException($why);
    }
}

function independentDenied(callable $call): void
{
    try {
        $call();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Expected domain denial.');
}

$independentResults = [];
function independentCase(string $name, callable $test): void
{
    global $independentResults;
    try {
        $test();
        $independentResults[] = ['case' => $name, 'result' => 'PASS'];
    } catch (Throwable $error) {
        $independentResults[] = ['case' => $name, 'result' => 'FAIL', 'reason' => $error->getMessage()];
    }
}

independentCase('default access denies inspect and all servicing before provider use', function (): void {
    [, $journal, , $provider] = independentSetup();
    $core = new PaymentCore($journal, ['independent_psp' => $provider]);
    foreach ([fn () => $core->inspect(independentBinding(), 'missing'), fn () => $core->recover(independentBinding(), 'missing'),
        fn () => $core->refund(independentBinding(), 'missing', 'refund_key', 1, 'other'),
        fn () => $core->start(independentBinding(), independentSale(), 'payment_key')] as $call) {
        independentDenied($call);
    }
    independentAssert($provider->supportsCalls + $provider->createCalls + $provider->findCalls === 0, 'Default denial contacted provider.');
});

independentCase('inspect has no provider or journal mutation and is original-binding scoped', function (): void {
    [$db, $journal, , $provider, $core] = independentSetup();
    $row = $journal->reservePayment(independentBinding(), independentSale(), 'payment_key');
    $before = $journal->get(665566, $row['id']);
    $events = $db->query('SELECT COUNT(*) FROM payment_core_events')->fetchColumn();
    independentAssert($core->inspect(independentBinding(), $row['id']) === $before, 'Inspection changed operation.');
    foreach (['tenant' => 665567, 'register' => 'foreign', 'provider' => 'foreign', 'merchant' => 'foreign', 'location' => 'foreign', 'environment' => 'live'] as $field => $value) {
        independentDenied(fn () => $core->inspect(independentBinding([$field => $value]), $row['id']));
    }
    independentAssert($journal->get(665566, $row['id']) === $before, 'Inspection mutated state.');
    independentAssert($db->query('SELECT COUNT(*) FROM payment_core_events')->fetchColumn() === $events, 'Inspection appended an event.');
    independentAssert($provider->supportsCalls + $provider->createCalls + $provider->findCalls === 0, 'Inspection contacted provider.');
});

independentCase('collection denied does not disable original unknown recovery', function (): void {
    [, $journal, , $provider, $core] = independentSetup();
    $row = $journal->reservePayment(independentBinding(), independentSale(), 'payment_key');
    $journal->claim(665566, $row['id']);
    independentDenied(fn () => $core->start(independentBinding(), independentSale('another_sale'), 'another_key'));
    $result = $core->recover(independentBinding(), $row['id']);
    independentAssert($result['id'] === $row['id'] && $result['state'] === 'unknown', 'Recovery replaced or resolved unknown evidence.');
    independentAssert($provider->createCalls === 0 && $provider->findCalls === 1, 'Recovery initiated collection.');
});

independentCase('collection denied does not disable manager-authorized original refund', function (): void {
    [, $journal, , $provider, $core] = independentSetup();
    $row = $journal->reservePayment(independentBinding(), independentSale(), 'payment_key');
    $journal->claim(665566, $row['id']);
    $journal->apply(665566, $row['id'], new ProviderEvidence('payment_key', independentBinding()->fingerprint(), 'paid_original', 1500, 'usd', 'succeeded', 1));
    $refund = $core->refund(independentBinding(), $row['id'], 'refund_key', 500, 'requested_by_customer');
    independentAssert($refund['state'] === 'succeeded' && $refund['original_provider_id'] === 'paid_original', 'Refund lost original servicing authority.');
    independentAssert($provider->createCalls === 1, 'Unexpected provider dispatch count.');
});

independentCase('revoked collection permission after reservation blocks provider dispatch', function (): void {
    [, $journal, $access, $provider, $core] = independentSetup();
    $access->allowed[] = 'collect';
    $access->revokeOnSecondCollect = true;
    $result = $core->start(independentBinding(), independentSale(), 'payment_key');
    independentAssert($result['state'] === 'unknown' && $result['claimed'] === 1, 'Revocation released durable claimed intent.');
    independentAssert($provider->createCalls === 0, 'Revocation failed to stop dispatch.');
});

independentCase('caller-owned transaction prevents provider creation', function (): void {
    [$db, , $access, $provider, $core] = independentSetup();
    $access->allowed[] = 'collect';
    $db->beginTransaction();
    try {
        independentDenied(fn () => $core->start(independentBinding(), independentSale(), 'payment_key'));
        independentAssert($provider->createCalls === 0, 'Provider effect happened inside outer transaction.');
    } finally {
        $db->rollBack();
    }
});

independentCase('caller-owned transaction prevents provider recovery lookup', function (): void {
    [$db, $journal, , $provider, $core] = independentSetup();
    $row = $journal->reservePayment(independentBinding(), independentSale(), 'payment_key');
    $journal->claim(665566, $row['id']);
    $db->beginTransaction();
    try {
        independentDenied(fn () => $core->recover(independentBinding(), $row['id']));
        independentAssert($provider->findCalls === 0, 'Provider lookup happened inside outer transaction.');
    } finally {
        $db->rollBack();
    }
});

$independentFailed = count(array_filter($independentResults, fn ($r) => $r['result'] === 'FAIL'));
echo json_encode(['synthetic_only' => true, 'cases' => $independentResults, 'passed' => count($independentResults) - $independentFailed,
    'total' => count($independentResults)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($independentFailed ? 1 : 0);
