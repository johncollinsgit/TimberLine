<?php

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\Simulation\FileSimulationStore;
use App\Services\PaymentsCore\Simulation\SimulatedPaymentProvider;
use App\Services\PaymentsCore\Simulation\SimulatedResponseLost;

/** Independent synthetic-provider checks; no network, account or production ledger. */
function independentInventorySimulationRow(array $overrides = [], ?MerchantBinding $binding = null): array
{
    $binding ??= new MerchantBinding(1, 'independent_counter', 'fixture_inventory', 'independent_merchant', 'independent_location', 'test');

    return array_replace(['kind' => 'payment', 'request_id' => '10000000-0000-4000-8000-000000000001',
        'binding' => $binding->snapshot(), 'terms_hash' => str_repeat('a', 64), 'amount_minor' => 2600,
        'currency' => 'usd', 'original_provider_id' => null], $overrides);
}

beforeEach(function (): void {
    $this->independentSimulationPath = sys_get_temp_dir().'/independent-inventory-test-'.bin2hex(random_bytes(12));
    $this->independentSimulationEnvironment = app()->environment();
});

afterEach(function (): void {
    app()->detectEnvironment(fn () => $this->independentSimulationEnvironment);
    foreach (glob($this->independentSimulationPath.'/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($this->independentSimulationPath)) {
        rmdir($this->independentSimulationPath);
    }
});

it('independently recovers the same synthetic effect after lost response and adapter restart', function (): void {
    $row = independentInventorySimulationRow();
    $first = new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath), 'succeeded', true);
    expect(fn () => $first->createPayment($row))->toThrow(SimulatedResponseLost::class);
    $restarted = new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath), 'failed');
    $canonical = $restarted->findPayment($row);
    expect($canonical)->not->toBeNull()
        ->and($canonical->state)->toBe('succeeded')
        ->and($restarted->createPayment($row))->toEqual($canonical);
    $stored = json_decode(file_get_contents($this->independentSimulationPath.'/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    expect($stored)->toHaveCount(1);
});

it('independently refuses changed immutable terms on both synthetic create and recovery', function (string $field, mixed $value): void {
    $row = independentInventorySimulationRow();
    $provider = new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath));
    $original = $provider->createPayment($row);
    $changed = array_replace($row, [$field => $value]);
    expect(fn () => $provider->createPayment($changed))->toThrow(DomainException::class)
        ->and(fn () => $provider->findPayment($changed))->toThrow(DomainException::class)
        ->and($provider->findPayment($row))->toEqual($original);
})->with([
    'amount' => ['amount_minor', 1],
    'currency' => ['currency', 'eur'],
    'terms' => ['terms_hash', str_repeat('b', 64)],
    'original-charge' => ['original_provider_id', 'another_charge'],
]);

it('independently separates identical request UUIDs by every immutable binding and operation kind', function (): void {
    $provider = new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath));
    $bindings = [
        new MerchantBinding(1, 'counter_a', 'fixture_inventory', 'merchant_a', 'location_a', 'test'),
        new MerchantBinding(2, 'counter_a', 'fixture_inventory', 'merchant_a', 'location_a', 'test'),
        new MerchantBinding(1, 'counter_b', 'fixture_inventory', 'merchant_a', 'location_a', 'test'),
        new MerchantBinding(1, 'counter_a', 'fixture_inventory', 'merchant_b', 'location_a', 'test'),
        new MerchantBinding(1, 'counter_a', 'fixture_inventory', 'merchant_a', 'location_b', 'test'),
    ];
    $effects = [];
    foreach ($bindings as $binding) {
        $effects[] = $provider->createPayment(independentInventorySimulationRow(binding: $binding))->providerId;
    }
    $refund = independentInventorySimulationRow(['kind' => 'refund', 'original_provider_id' => $effects[0]], $bindings[0]);
    $effects[] = $provider->createRefund($refund)->providerId;
    expect(array_unique($effects))->toHaveCount(count($effects))
        ->and($provider->findRefund($refund)->originalPaymentId)->toBe($effects[0]);
});

it('independently denies a retained synthetic adapter after the environment becomes production', function (): void {
    $row = independentInventorySimulationRow();
    $provider = new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath));
    $provider->createPayment($row);
    config()->set(['evergrove_payments.fixture_mode' => true, 'evergrove_payments.collection_enabled' => true,
        'evergrove_payments.fixture_collection_enabled' => true]);
    app()->detectEnvironment(fn () => 'production');
    expect($provider->supports(MerchantBinding::restore($row['binding'])))->toBeFalse()
        ->and(fn () => $provider->createPayment($row))->toThrow(DomainException::class)
        ->and(fn () => $provider->findPayment($row))->toThrow(DomainException::class)
        ->and(fn () => new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath)))->toThrow(DomainException::class);
});

it('independently rejects live bindings and routes no extra operation fields into the fixture ledger', function (): void {
    $provider = new SimulatedPaymentProvider(new FileSimulationStore($this->independentSimulationPath));
    $live = new MerchantBinding(1, 'independent_counter', 'fixture_inventory', 'independent_merchant', 'independent_location', 'live');
    expect(fn () => $provider->createPayment(independentInventorySimulationRow(binding: $live)))->toThrow(DomainException::class);
    $provider->createPayment(independentInventorySimulationRow(['extra_untrusted' => 'UNTRUSTED_SECRET_SENTINEL']));
    expect(file_get_contents($this->independentSimulationPath.'/fixture.json'))->not->toContain('UNTRUSTED_SECRET_SENTINEL');
});
