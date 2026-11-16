<?php

use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Services\Team\Studio\EventCustomerDependencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => '']);
    $this->tenant = Tenant::create(['name' => 'Fictional financial dependencies', 'slug' => 'financial-dependency-fixture']);
    $this->otherTenant = Tenant::create(['name' => 'Other fictional financial dependencies', 'slug' => 'other-financial-dependency-fixture']);
    $this->readFinancial = function (array $keys): array {
        return (new ReflectionMethod(EventCustomerDependencies::class, 'financial'))
            ->invoke(app(EventCustomerDependencies::class), $this->tenant, $keys, []);
    };
});

test('original payment source union keeps every tender and explicit payment once within its tenant', function () {
    foreach (['original-order', 'explicit-payment-order'] as $id) {
        SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $id]);
    }
    $create = fn (int $tenant, string $id, string $order, string $customer) => SquarePayment::create([
        'tenant_id' => $tenant, 'square_payment_id' => $id, 'square_order_id' => $order,
        'square_customer_id' => $customer, 'status' => 'COMPLETED', 'amount_money' => 550, 'currency' => 'USD',
        'created_at_source' => '2026-11-01 12:00:00', 'raw_payload' => ['retained' => ['refunds' => ['amount' => 0]]],
    ]);
    $payment = $create($this->tenant->id, 'overlapping-payment', 'original-order', 'original-customer');
    $create($this->tenant->id, 'unlinked-original-tender', 'original-order', 'other-original-customer');
    $create($this->tenant->id, 'explicit-payment', 'explicit-payment-order', 'explicit-customer');
    $create($this->tenant->id, 'unrelated-payment', 'unrelated-order', 'unrelated-customer');
    $create($this->otherTenant->id, 'foreign-payment', 'original-order', 'foreign-customer');
    $create($this->otherTenant->id, 'explicit-payment', 'foreign-order', 'foreign-customer');
    $keys = ['square_order' => ['original-order' => true], 'square_payment' => ['overlapping-payment' => true, 'explicit-payment' => true]];
    $result = ($this->readFinancial)($keys);
    expect(array_column($result['revision']['payments'], 'square_payment_id'))->toBe([
        'overlapping-payment', 'unlinked-original-tender', 'explicit-payment',
    ])->and($result['order_tenders']['original-order'])->toBe(['overlapping-payment', 'unlinked-original-tender'])
        ->and(array_keys($result['keys']['square_order']))->toBe(['original-order', 'explicit-payment-order'])
        ->and(array_keys($result['keys']['square_customer']))->not->toContain('unrelated-customer', 'foreign-customer');
    $original = $result['revision']['payments'][0]->source_revision;
    $payment->update(['raw_payload' => ['retained' => ['refunds' => ['amount' => null]]]]);
    expect(($this->readFinancial)($keys)['revision']['payments'][0]->source_revision)->not->toBe($original);
    Http::assertNothingSent();
});

test('the deduplicated payment population retains the original global dependency cap', function () {
    for ($offset = 0; $offset < 50001; $offset += 1000) {
        $rows = [];
        for ($i = $offset; $i < min($offset + 1000, 50001); $i++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'square_payment_id' => 'bounded-payment-'.$i, 'square_order_id' => 'bounded-order'];
        }
        DB::table('square_payments')->insert($rows);
    }
    try {
        ($this->readFinancial)(['square_order' => ['bounded-order' => true], 'square_payment' => ['bounded-payment-0' => true]]);
        $this->fail('The whole deduplicated population must retain its original dependency bound.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($error->getMessage())->toBe('Customer dependency coverage needs review.');
    }
    Http::assertNothingSent();
});
