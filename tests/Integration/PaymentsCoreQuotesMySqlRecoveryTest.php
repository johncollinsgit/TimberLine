<?php

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\Quotes\CatalogLine;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\TaxAssessment;
use App\Services\PaymentsCore\Quotes\TrustedQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable PaymentsCore MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $connection->getDatabaseName())
        || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing quote recovery fixtures outside local disposable MySQL.');
    }
    $this->migration = require database_path('migrations/2026_10_22_190100_create_payment_core_quotes_table.php');
    $this->migration->up();
    $this->quoteId = (string) Str::uuid();
    $this->requestId = (string) Str::uuid();
    // This recovery sentinel is deliberately not a service-issued quote. Only its exact
    // bytes matter to DDL recovery; production hydration must reject arbitrary snapshots.
    $this->sentinel = app('encrypter')->encryptString('fictional quote DDL retained sentinel');
    DB::table('payment_core_quotes')->insert(['quote_id' => $this->quoteId, 'sale_id' => (string) Str::uuid(),
        'tenant_id' => 900000001, 'register_id' => 'fictional_recovery_counter', 'actor_id' => 900000002, 'site_id' => 900000003,
        'request_id' => $this->requestId, 'version' => 1, 'binding_fingerprint' => str_repeat('a', 64),
        'scope_hash' => str_repeat('b', 64), 'selection_hash' => str_repeat('c', 64), 'payload_hash' => str_repeat('d', 64),
        'snapshot' => $this->sentinel, 'created_at' => '2030-01-01 00:00:00', 'expires_at' => '2030-01-01 00:05:00']);
});

afterEach(function (): void {
    if (isset($this->quoteId)) {
        DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->delete();
    }
});

it('creates an absent quote table then safely repeats completed DDL before migration bookkeeping', function (): void {
    $row = (array) DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->first();
    expect(DB::table('payment_core_quotes')->count())->toBe(1);
    Schema::drop('payment_core_quotes');
    $this->migration->up();
    DB::table('payment_core_quotes')->insert($row);
    $this->migration->up();
    expect((array) DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->first())->toBe($row);
});

it('repairs retained quote rows before every named index and repeats completed DDL without changing evidence', function (): void {
    $before = (array) DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->first();
    foreach (['pcq_quote_uq', 'pcq_request_uq', 'pcq_sale_uq', 'pcq_scope_idx'] as $name) {
        Schema::table('payment_core_quotes', fn ($table) => $table->dropIndex($name));
    }
    $this->migration->up();
    $this->migration->up();
    expect((array) DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->first())->toBe($before);
    foreach (['pcq_quote_uq', 'pcq_request_uq', 'pcq_sale_uq', 'pcq_scope_idx'] as $name) {
        expect(Schema::hasIndex('payment_core_quotes', $name))->toBeTrue();
    }
});

it('stops on a same-named changed index without rewriting retained quote evidence', function (): void {
    Schema::table('payment_core_quotes', fn ($table) => $table->dropIndex('pcq_scope_idx'));
    Schema::table('payment_core_quotes', fn ($table) => $table->index(['actor_id'], 'pcq_scope_idx'));
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'Payment quote index definition changed');
        expect(DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->value('snapshot'))->toBe($this->sentinel);
    } finally {
        Schema::table('payment_core_quotes', fn ($table) => $table->dropIndex('pcq_scope_idx'));
        $this->migration->up();
    }
});

it('refuses duplicate retained request identities during recovery and leaves every row intact', function (): void {
    Schema::table('payment_core_quotes', fn ($table) => $table->dropIndex('pcq_request_uq'));
    $duplicateId = (string) Str::uuid();
    $row = (array) DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->first();
    unset($row['id']);
    $row['quote_id'] = $duplicateId;
    $row['sale_id'] = (string) Str::uuid();
    DB::table('payment_core_quotes')->insert($row);
    try {
        expect(fn () => $this->migration->up())->toThrow(Illuminate\Database\QueryException::class);
        expect(DB::table('payment_core_quotes')->whereIn('quote_id', [$this->quoteId, $duplicateId])->count())->toBe(2);
        expect(DB::table('payment_core_quotes')->where('quote_id', $this->quoteId)->value('snapshot'))->toBe($this->sentinel);
    } finally {
        DB::table('payment_core_quotes')->where('quote_id', $duplicateId)->delete();
        $this->migration->up();
    }
});

it('serializes eight concurrent quote UUID replays into one immutable sale and survives repository restart', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires the pcntl extension for real process contention.');
    }
    $requestId = (string) Str::uuid();
    $directory = sys_get_temp_dir().'/payment-quote-race-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $pids = [];
    // No inherited live PDO connection across fork. Each worker obtains its own socket.
    DB::purge();
    try {
        for ($index = 0; $index < 8; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start isolated quote race worker.');
            }
            if ($pid === 0) {
                try {
                    $binding = new MerchantBinding(900000001, 'fictional_quote_race', 'fixture_provider', 'fictional_quote_account', 'fictional_counter', 'test');
                    $line = new CatalogLine(1, 1, 'merchandise', 'usd', 1000, str_repeat('a', 64), false, null, 'Fictional item', 'FIXTURE-QUOTE');
                    $created = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
                    $tax = new TaxAssessment('fictional_tax_authority', 'fictional_tax_v1', TaxAssessment::scope($binding, 900000003),
                        TaxAssessment::terms([$line]), 'usd', 'exclusive', 'per_line_half_up', [$line->lineId => 70], $created, $created->modify('+10 minutes'));
                    $candidate = new TrustedQuote((string) Str::uuid(), (string) Str::uuid(), $binding, 900000002, 900000003,
                        $requestId, [['variant_id' => 1, 'quantity' => 1, 'expected_revision' => $line->revision]], [$line], $tax, $created, $created->modify('+5 minutes'));
                    $repository = new DatabaseQuoteRepository(DB::connection(), app('encrypter'));
                    $stored = $repository->saveIfAbsent($candidate);
                    file_put_contents($directory.'/'.$index.'.json', json_encode(['quote_id' => $stored->id, 'sale_id' => $stored->saleId, 'amount' => $stored->amountMinor], JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/'.$index.'.json', json_encode(['error' => get_class($exception)], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = [];
        for ($index = 0; $index < 8; $index++) {
            $results[] = json_decode(file_get_contents($directory.'/'.$index.'.json'), true, 8, JSON_THROW_ON_ERROR);
        }
        expect(array_unique(array_column($results, 'quote_id')))->toHaveCount(1)
            ->and(array_unique(array_column($results, 'sale_id')))->toHaveCount(1)
            ->and(array_unique(array_column($results, 'amount')))->toBe([1070])
            ->and(DB::table('payment_core_quotes')->where('tenant_id', 900000001)->where('request_id', $requestId)->count())->toBe(1);
        $restarted = new DatabaseQuoteRepository(DB::connection(), app('encrypter'));
        expect($restarted->findByRequest(900000001, strtoupper($requestId))->id)->toBe($results[0]['quote_id'])
            ->and($restarted->findByRequest(900000001, $requestId)->saleId)->toBe($results[0]['sale_id']);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('payment_core_quotes')->where('tenant_id', 900000001)->where('request_id', $requestId)->delete();
        for ($index = 0; $index < 8; $index++) {
            if (is_file($directory.'/'.$index.'.json')) {
                unlink($directory.'/'.$index.'.json');
            }
        }
        rmdir($directory);
    }
});
