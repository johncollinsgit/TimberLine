<?php

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\PaymentsCore\Simulation\FileSimulationStore;
use App\Services\PaymentsCore\Simulation\SimulatedPaymentProvider;
use App\Services\PaymentsCore\SqlitePaymentJournal;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->simulationDirectory = sys_get_temp_dir().'/mobile-inventory-simulation-'.Str::uuid();
    $this->journal = new SqlitePaymentJournal(new PDO('sqlite::memory:'));
    $this->journal->installForTesting();
    $this->binding = new MerchantBinding(991, 'fixture_register', 'fixture_inventory', 'fictional_merchant', 'fictional_location', 'test');
    $this->sale = new SaleSnapshot((string) Str::uuid(), 'usd', [['line_id' => 'fictional_line',
        'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 0]]);
    $this->access = new class implements PaymentAccess
    {
        public function assertAllowed(MerchantBinding $binding, string $operation): void
        {
            if (! app()->environment('testing') || $binding->environment !== 'test' || $binding->provider !== 'fixture_inventory') {
                throw new DomainException('Synthetic test authority denied.');
            }
        }
    };
});

afterEach(function (): void {
    File::deleteDirectory($this->simulationDirectory);
    Http::assertNothingSent();
});

it('recovers one durable fictional effect after a lost response and provider reconstruction', function (): void {
    // This verifies the simulated adapter only; MySQL HTTP tests establish inventory atomicity.
    $provider = new SimulatedPaymentProvider(new FileSimulationStore($this->simulationDirectory), 'succeeded', true);
    $core = new PaymentCore($this->journal, ['fixture_inventory' => $provider], $this->access);
    $requestId = (string) Str::uuid();
    $attempt = $core->start($this->binding, $this->sale, $requestId);
    expect($attempt['state'])->toBe('unknown');
    $restartedProvider = new SimulatedPaymentProvider(new FileSimulationStore($this->simulationDirectory));
    $restarted = new PaymentCore($this->journal, ['fixture_inventory' => $restartedProvider], $this->access);
    $recovered = $restarted->recover($this->binding, $attempt['id']);
    expect($recovered['state'])->toBe('succeeded')
        ->and($restarted->start($this->binding, $this->sale, $requestId)['id'])->toBe($attempt['id'])
        ->and($restarted->recover($this->binding, $attempt['id'])['provider_id'])->toBe($recovered['provider_id']);
    $saved = json_decode(file_get_contents($this->simulationDirectory.'/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    expect($saved)->toHaveCount(1)
        ->and(file_get_contents($this->simulationDirectory.'/fixture.json'))->not->toContain('fictional_merchant', 'fictional_location');
});

it('refuses changed frozen terms under an existing fictional provider request', function (): void {
    $provider = new SimulatedPaymentProvider(new FileSimulationStore($this->simulationDirectory));
    $row = $this->journal->reservePayment($this->binding, $this->sale, (string) Str::uuid());
    $provider->createPayment($row);
    $row['terms_hash'] = str_repeat('f', 64);
    expect(fn () => $provider->createPayment($row))->toThrow(DomainException::class, 'frozen terms');
});

it('cannot instantiate or serve the fictional adapter in production or for a live binding', function (): void {
    $store = new FileSimulationStore($this->simulationDirectory);
    $provider = new SimulatedPaymentProvider($store);
    $live = new MerchantBinding(991, 'fixture_register', 'fixture_inventory', 'fictional_merchant', 'fictional_location', 'live');
    expect($provider->supports($live))->toBeFalse();
    app()->instance('env', 'production');
    expect($provider->supports($this->binding))->toBeFalse()
        ->and(fn () => new SimulatedPaymentProvider($store))->toThrow(DomainException::class)
        ->and(fn () => new FileSimulationStore($this->simulationDirectory))->toThrow(DomainException::class);
});
