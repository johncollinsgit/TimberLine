<?php

declare(strict_types=1);

// Isolated contract/security tests: no Laravel boot, .env, HTTP or provider SDK.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\Services\\PaymentsCore\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../../app/Services/PaymentsCore/'.substr($class, strlen($prefix)).'.php';
    }
});

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\PaymentsCore\SqlitePaymentJournal;

final class FixtureAccess implements PaymentAccess
{
    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        if ($binding->tenantId !== 16 || $binding->merchantAccount !== 'alpha_fixture'
            || $binding->registerId !== 'register_fixture' || $binding->locationId !== 'location_fixture'
            || ! in_array($operation, ['collect', 'recover', 'refund'], true)) {
            throw new DomainException('Fixture actor is not authorized.');
        }
    }
}

/** Independent durable fake provider, never registered in the application. */
final class FixtureProvider implements PaymentProvider
{
    public string $nextState = 'succeeded';

    public bool $loseResponse = false;

    public ?string $mismatch = null;

    public function __construct(private PDO $db, private SqlitePaymentJournal $journal)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 10000');
        $db->exec('CREATE TABLE IF NOT EXISTS fixture_evidence (request_id TEXT PRIMARY KEY, evidence TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS fixture_calls (request_id TEXT NOT NULL)');
    }

    public function supports(MerchantBinding $binding): bool
    {
        return $binding->provider === 'fixture_provider' && $binding->merchantAccount === 'alpha_fixture';
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

    public function update(array $row, string $state, int $version): void
    {
        $evidence = $this->evidence($row, $state, $version);
        $statement = $this->db->prepare('UPDATE fixture_evidence SET evidence = ? WHERE request_id = ?');
        $statement->execute([json_encode((array) $evidence, JSON_THROW_ON_ERROR), $row['request_id']]);
    }

    public function calls(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM fixture_calls')->fetchColumn();
    }

    private function create(array $row): ProviderEvidence
    {
        // Separate connection sees a committed claim BEFORE a provider operation.
        $statement = $this->db->prepare('SELECT claimed FROM payment_core_operations WHERE id = ?');
        $statement->execute([$row['id']]);
        check((int) $statement->fetchColumn() === 1, 'dispatch claim must be committed before adapter');
        $statement = $this->db->prepare('INSERT INTO fixture_calls VALUES (?)');
        $statement->execute([$row['request_id']]);
        $evidence = $this->evidence($row, $this->nextState, 1);
        $statement = $this->db->prepare('INSERT INTO fixture_evidence VALUES (?, ?)');
        $statement->execute([$row['request_id'], json_encode((array) $evidence, JSON_THROW_ON_ERROR)]);
        if ($this->loseResponse) {
            throw new RuntimeException('Fixture response lost after provider acceptance.');
        }

        return $evidence;
    }

    private function find(array $row): ?ProviderEvidence
    {
        $statement = $this->db->prepare('SELECT evidence FROM fixture_evidence WHERE request_id = ?');
        $statement->execute([$row['request_id']]);
        $json = $statement->fetchColumn();

        return $json ? new ProviderEvidence(...json_decode($json, true, 512, JSON_THROW_ON_ERROR)) : null;
    }

    private function evidence(array $row, string $state, int $version): ProviderEvidence
    {
        $binding = MerchantBinding::restore($row['binding']);

        return new ProviderEvidence($this->mismatch === 'operation' ? 'wrong' : $row['request_id'],
            $this->mismatch === 'binding' ? str_repeat('0', 64) : $binding->fingerprint(),
            'fixture_'.$row['request_id'], $row['amount_minor'] + ($this->mismatch === 'amount' ? 1 : 0),
            $this->mismatch === 'currency' ? 'eur' : $row['currency'], $state, $version,
            $this->mismatch === 'original' ? 'wrong' : $row['original_provider_id']);
    }
}

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function rejected(callable $operation): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Expected fail-closed rejection.');
}

function binding(string $register = 'register_fixture'): MerchantBinding
{
    return new MerchantBinding(16, $register, 'fixture_provider', 'alpha_fixture', 'location_fixture', 'test');
}

function sale(string $id = 'sale_fixture', int $price = 1000): SaleSnapshot
{
    return new SaleSnapshot($id, 'usd', [['line_id' => 'line_fixture', 'kind' => 'printing_service',
        'quantity' => 1, 'unit_amount_minor' => $price, 'tax_amount_minor' => 70]]);
}

function fixture(string $path): array
{
    $journal = new SqlitePaymentJournal(new PDO('sqlite:'.$path));
    $journal->installForTesting();
    $provider = new FixtureProvider(new PDO('sqlite:'.$path), $journal);

    return [$journal, $provider, new PaymentCore($journal, ['fixture_provider' => $provider], new FixtureAccess)];
}

function race(string $path, callable $work): void
{
    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork race worker.');
        }
        if ($pid === 0) {
            try {
                $work($i, ...fixture($path));
                exit(0);
            } catch (Throwable $error) {
                fwrite(STDERR, 'Race worker: '.$error->getMessage().PHP_EOL);
                exit(1);
            }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Race child failed.');
    }
}

$directory = sys_get_temp_dir().'/evergrove-core-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$tests = [];
$tests['native snake_case fixture has the same integer total and typed lines'] = function (string $path): void {
    $request = json_decode(file_get_contents(__DIR__.'/fixtures/native-checkout.json'), true, 512, JSON_THROW_ON_ERROR);
    $snapshot = new SaleSnapshot($request['sale_id'], $request['currency'], $request['lines']);
    check($snapshot->amountMinor === 3730);
    check(array_column($snapshot->lines, 'kind') === ['merchandise', 'printing_service', 'postage']);
};
$tests['default authorization and missing provider deny before writes'] = function (string $path): void {
    [$journal, $provider] = fixture($path);
    rejected(fn () => (new PaymentCore($journal, ['fixture_provider' => $provider]))->start(binding(), sale(), 'request_1'));
    rejected(fn () => (new PaymentCore($journal, [], new FixtureAccess))->start(binding(), sale(), 'request_1'));
    check($provider->calls() === 0);
};
$tests['reference journal refuses live collection'] = function (string $path): void {
    [, $provider, $core] = fixture($path);
    $live = new MerchantBinding(16, 'register_fixture', 'fixture_provider', 'alpha_fixture', 'location_fixture', 'live');
    rejected(fn () => $core->start($live, sale(), 'request_live'));
    check($provider->calls() === 0);
};
$tests['immutable request replay and original sale cannot charge twice'] = function (string $path): void {
    [, $provider, $core] = fixture($path);
    $row = $core->start(binding(), sale(), 'request_1');
    check($row['state'] === 'succeeded');
    check($core->start(binding(), sale(), 'request_1')['id'] === $row['id']);
    rejected(fn () => $core->start(binding(), sale(price: 2000), 'request_1'));
    rejected(fn () => $core->start(binding(), sale(), 'request_2'));
    check($provider->calls() === 1);
};
$tests['all identity dimensions are bound and original servicing stays scoped'] = function (string $path): void {
    [, , $core] = fixture($path);
    $row = $core->start(binding(), sale(), 'request_1');
    foreach ([new MerchantBinding(17, 'register_fixture', 'fixture_provider', 'alpha_fixture', 'location_fixture', 'test'),
        new MerchantBinding(16, 'register_other', 'fixture_provider', 'alpha_fixture', 'location_fixture', 'test'),
        new MerchantBinding(16, 'register_fixture', 'other_provider', 'alpha_fixture', 'location_fixture', 'test'),
        new MerchantBinding(16, 'register_fixture', 'fixture_provider', 'other_merchant', 'location_fixture', 'test'),
        new MerchantBinding(16, 'register_fixture', 'fixture_provider', 'alpha_fixture', 'other_location', 'test'),
        new MerchantBinding(16, 'register_fixture', 'fixture_provider', 'alpha_fixture', 'location_fixture', 'live')] as $changed) {
        rejected(fn () => $core->recover($changed, $row['id']));
        rejected(fn () => $core->refund($changed, $row['id'], 'refund_1', 100, 'other'));
        rejected(fn () => $core->start($changed, sale('other_sale'), 'other_request'));
    }
};
$tests['lost create response recovers independently after process restart'] = function (string $path): void {
    [, $provider, $core] = fixture($path);
    $provider->loseResponse = true;
    $row = $core->start(binding(), sale(), 'request_lost');
    check($row['state'] === 'unknown');
    rejected(fn () => $core->start(binding(), sale('new_sale'), 'new_request'));
    [, $provider2, $core2] = fixture($path);
    check($core2->start(binding(), sale(), 'request_lost')['state'] === 'unknown');
    check($core2->recover(binding(), $row['id'])['state'] === 'succeeded');
    check($provider2->calls() === 1);
};
$tests['crash after claim is never blind retried or replaced'] = function (string $path): void {
    [$journal, $provider, $core] = fixture($path);
    $row = $journal->reservePayment(binding(), sale(), 'request_crash');
    check($journal->claim(16, $row['id']));
    check($core->start(binding(), sale(), 'request_crash')['state'] === 'processing');
    check($core->recover(binding(), $row['id'])['state'] === 'unknown');
    rejected(fn () => $core->start(binding(), sale('replacement'), 'new_request'));
    check($provider->calls() === 0);
};
$tests['mismatched provider evidence never establishes paid'] = function (string $path): void {
    foreach (['binding', 'operation', 'amount', 'currency', 'original'] as $mismatch) {
        [, $provider, $core] = fixture($path.'_'.$mismatch);
        $provider->mismatch = $mismatch;
        $row = $core->start(binding(), sale(), 'request_bad');
        check($row['state'] === 'review', $mismatch.' must require review');
        rejected(fn () => $core->start(binding(), sale('replacement'), 'new_request'));
    }
};
$tests['provider id and monotonic revision protect reconciliation'] = function (string $path): void {
    [$journal, $provider, $core] = fixture($path);
    $provider->nextState = 'processing';
    $row = $core->start(binding(), sale(), 'request_process');
    $provider->update($row, 'succeeded', 3);
    check($core->recover(binding(), $row['id'])['state'] === 'succeeded');
    $late = new ProviderEvidence('request_process', binding()->fingerprint(), $row['provider_id'], 1070, 'usd', 'failed', 4);
    check($journal->apply(16, $row['id'], $late)['state'] === 'succeeded');
    check($journal->unknown(16, $row['id'])['state'] === 'succeeded');
    check((int) (new PDO('sqlite:'.$path))->query("SELECT COUNT(*) FROM payment_core_events WHERE event_code = 'terminal_evidence_conflict'")->fetchColumn() === 1);
};
$tests['one canonical provider effect cannot credit two original operations'] = function (string $path): void {
    [$journal] = fixture($path);
    $one = $journal->reservePayment(binding(), sale('sale_one'), 'request_one');
    $journal->claim(16, $one['id']);
    $journal->apply(16, $one['id'], new ProviderEvidence('request_one', binding()->fingerprint(), 'same_provider_effect', 1070, 'usd', 'succeeded', 1));
    $otherBinding = binding('second_register');
    $two = $journal->reservePayment($otherBinding, sale('sale_two'), 'request_two');
    $journal->claim(16, $two['id']);
    check($journal->apply(16, $two['id'], new ProviderEvidence('request_two', $otherBinding->fingerprint(), 'same_provider_effect', 1070, 'usd', 'succeeded', 1))['state'] === 'review');
    check((int) (new PDO('sqlite:'.$path))->query("SELECT COUNT(*) FROM payment_core_operations WHERE state = 'succeeded'")->fetchColumn() === 1);
};
$tests['unknown partial refunds reserve budget and use original account'] = function (string $path): void {
    [, $provider, $core] = fixture($path);
    $payment = $core->start(binding(), sale(), 'request_paid');
    $provider->loseResponse = true;
    $refund = $core->refund(binding(), $payment['id'], 'refund_1', 700, 'requested_by_customer');
    check($refund['state'] === 'unknown' && $refund['original_provider_id'] === $payment['provider_id']);
    rejected(fn () => $core->refund(binding(), $payment['id'], 'refund_2', 371, 'other'));
    rejected(fn () => $core->refund(binding(), $payment['id'], 'refund_1', 701, 'requested_by_customer'));
    check($core->refund(binding(), $payment['id'], 'refund_1', 700, 'requested_by_customer')['id'] === $refund['id']);
    check($core->recover(binding(), $refund['id'])['state'] === 'succeeded');
    $provider->loseResponse = false;
    check($core->refund(binding(), $payment['id'], 'refund_2', 370, 'other')['state'] === 'succeeded');
    rejected(fn () => $core->refund(binding(), $payment['id'], 'refund_3', 1, 'other'));
    check($provider->calls() === 3);
};
$tests['unpaid and cross-kind idempotency cannot refund'] = function (string $path): void {
    [, $provider, $core] = fixture($path);
    $provider->nextState = 'processing';
    $payment = $core->start(binding(), sale(), 'request_pending');
    rejected(fn () => $core->refund(binding(), $payment['id'], 'refund_1', 100, 'other'));
    $provider->update($payment, 'succeeded', 2);
    $core->recover(binding(), $payment['id']);
    rejected(fn () => $core->refund(binding(), $payment['id'], 'request_pending', 100, 'other'));
};
$tests['integer amounts typed service lines and strict fields'] = function (string $path): void {
    $lines = [];
    foreach (['merchandise', 'printing_service', 'postage'] as $i => $kind) {
        $lines[] = ['line_id' => 'line_'.$i, 'kind' => $kind, 'quantity' => 2, 'unit_amount_minor' => 100, 'tax_amount_minor' => 10];
    }
    $snapshot = new SaleSnapshot('sale', 'usd', $lines);
    check($snapshot->amountMinor === 630);
    check($snapshot->snapshot() === (new SaleSnapshot('sale', 'usd', array_reverse($lines)))->snapshot());
    foreach ([['quantity' => 0], ['kind' => 'unknown'], ['unit_amount_minor' => -1], ['tax_amount_minor' => -1],
        ['unit_amount_minor' => 100000000], ['pan' => 'prohibited-fixture']] as $bad) {
        rejected(fn () => new SaleSnapshot('sale', 'usd', [[...$lines[0], ...$bad]]));
    }
};
$tests['eight concurrent replays dispatch exactly once'] = function (string $path): void {
    fixture($path);
    race($path, function (int $i, SqlitePaymentJournal $journal, FixtureProvider $provider, PaymentCore $core): void {
        $core->start(binding(), sale(), 'same_request');
    });
    [, $provider] = fixture($path);
    check($provider->calls() === 1);
};
$tests['eight competing register sales admit one unresolved operation'] = function (string $path): void {
    fixture($path);
    race($path, function (int $i, SqlitePaymentJournal $journal, FixtureProvider $provider, PaymentCore $core): void {
        $provider->nextState = 'processing';
        try {
            $core->start(binding(), sale('sale_'.$i), 'request_'.$i);
        } catch (DomainException) {
            // Competing requests are expected to lose admission.
        }
    });
    [, $provider] = fixture($path);
    check($provider->calls() === 1);
};
$tests['eight competing refunds cannot exceed original value'] = function (string $path): void {
    [, , $core] = fixture($path);
    $payment = $core->start(binding(), sale(), 'paid');
    race($path, function (int $i, SqlitePaymentJournal $journal, FixtureProvider $provider, PaymentCore $core) use ($payment): void {
        $provider->nextState = 'processing';
        try {
            $core->refund(binding(), $payment['id'], 'refund_'.$i, 300, 'other');
        } catch (DomainException) {
            // A pending refund reserves its value before the next process.
        }
    });
    [, $provider] = fixture($path);
    check($provider->calls() === 4, 'One payment plus exactly three reserved refunds expected.');
};

$tests['explicit native review parking retains uncertainty and prevents replacement of its original sale'] = function (string $path): void {
    [$journal] = fixture($path);
    $binding = new MerchantBinding(16, 'native_register', 'square_native', 'alpha_fixture', 'location_fixture', 'test');
    $original = $journal->reservePayment($binding, sale('original'), 'original_request');
    rejected(fn () => $journal->parkForReview(16, $original['id']));
    check($journal->claim(16, $original['id']));
    $journal->unknown(16, $original['id']);
    rejected(fn () => $journal->reservePayment($binding, sale('different'), 'different_request'));
    rejected(fn () => $journal->parkForReview(17, $original['id']));
    $parked = $journal->parkForReview(16, $original['id']);
    check($parked['state'] === 'unknown' && $parked['provider_id'] === null && $parked['parked_for_review']);
    check($journal->parkForReview(16, $original['id']) === $parked);
    rejected(fn () => $journal->reservePayment($binding, sale('original'), 'replacement_request'));
    check(! $journal->claim(16, $original['id']));
    $next = $journal->reservePayment($binding, sale('different'), 'different_request');
    check($next['id'] !== $original['id']);
    [$other] = fixture($path.'.other');
    $unsupported = $other->reservePayment(binding(), sale(), 'unsupported');
    $other->claim(16, $unsupported['id']);
    $other->unknown(16, $unsupported['id']);
    rejected(fn () => $other->parkForReview(16, $unsupported['id']));
};

$passed = 0;
try {
    foreach ($tests as $name => $test) {
        $test($directory.'/test_'.$passed.'.sqlite');
        $passed++;
        echo 'PASS '.$name.PHP_EOL;
    }
    echo $passed.' scenarios passed; fixture provider only; no live API, MySQL or native integration.'.PHP_EOL;
} finally {
    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
