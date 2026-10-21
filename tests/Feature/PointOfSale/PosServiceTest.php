<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosSimulatorObject;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsitePayment;
use App\Services\PointOfSale\DatabaseSimulatedReader;
use App\Services\PointOfSale\PosAccess;
use App\Services\PointOfSale\PosService;
use App\Services\PointOfSale\SimulatedReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosFixture;

function expectPosConflict(Closure $operation): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);

        return;
    }
    test()->fail('Expected the conflicting POS operation to be rejected.');
}

function expectPosInputRejected(Closure $operation): void
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        expect($exception->status)->toBe(422);

        return;
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);

        return;
    }
    test()->fail('Expected invalid POS tender input to be rejected.');
}

function posReaderMock(string $key = 'simulated_reader_v1', ?array $capabilities = null): Mockery\MockInterface
{
    $reader = Mockery::mock(SimulatedReader::class);
    $reader->shouldReceive('key')->andReturn($key);
    $reader->shouldReceive('capabilities')->andReturn($capabilities ?? ['environment' => 'simulation', 'physical_readers' => false, 'offline' => false, 'partial_refunds' => true, 'tenders' => ['card', 'cash']]);

    return $reader;
}

function posNativeCustomer(PosFixture $fixture, string $suffix = 'one'): WebsiteCustomer
{
    return WebsiteCustomer::query()->create(['tenant_id' => $fixture->tenant->id, 'first_name' => 'Casey',
        'last_name' => 'Fictional '.$suffix, 'email' => 'casey-'.$suffix.'@example.test', 'phone' => '+12025550123', 'status' => 'active',
        'notes' => ['private' => 'Fictional staff-only note excluded from a counter receipt']]);
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->fixture = PosFixture::merchant();
    $this->pos = app(PosService::class);
    $this->start = fn (?array $input = null) => $this->pos->start($this->fixture->tenant, $this->fixture->cashier,
        $this->fixture->register->id, $input ?? $this->fixture->input());
    $this->paid = function (int $quantity = 1): PosPaymentAttempt {
        $attempt = ($this->start)($this->fixture->input($quantity));

        return $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('freezes server prices and the confirmed tax policy and never creates a live payment record', function (): void {
    $attempt = ($this->start)($this->fixture->input(2));
    $order = $attempt->order;
    expect($order->source)->toBe('pos_simulation')->and($order->currency)->toBe('usd')
        ->and($order->subtotal_cents)->toBe(5000)->and($order->tax_cents)->toBe(413)->and($order->total_cents)->toBe(5413)
        ->and($order->lines()->sole()->unit_price_cents)->toBe(2500)
        ->and($attempt->status)->toBe('awaiting_reader')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryReservation::query()->sole()->quantity)->toBe(2)
        ->and(Order::query()->count())->toBe(0)->and(WebsitePayment::query()->count())->toBe(0);
    expect(DB::table('pos_payment_attempts')->value('snapshot'))->not->toContain('sim_merchant_one')->not->toContain('Fictional');
});

it('does not accept client money or routing as authoritative', function (): void {
    $input = [...$this->fixture->input(), 'total_cents' => 1, 'tax_cents' => 0, 'currency' => 'eur',
        'merchant_id' => 'acct_not_this_merchant', 'reader_id' => 'reader_not_this_register', 'provider_key' => 'other_processor'];
    try {
        $attempt = ($this->start)($input);
    } catch (ValidationException) {
        expect(PosPaymentAttempt::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(0);

        return;
    }
    expect($attempt->order->total_cents)->toBe(2706)->and($attempt->order->currency)->toBe('usd')
        ->and($attempt->snapshot['provider_key'])->toBe('simulated_reader_v1');
    $canonical = PosSimulatorObject::query()->sole()->payload;
    expect($canonical['merchant_id'])->toBe($this->fixture->register->merchant_id)
        ->and($canonical['reader_id'])->toBe($this->fixture->register->reader_id)->and($canonical['amount_cents'])->toBe(2706);
});

it('rejects insufficient missing or invalid cash before any sale or provider writes', function (mixed $received, bool $include): void {
    $input = [...$this->fixture->input(), 'tender' => 'cash'];
    if ($include) {
        $input['cash_received_cents'] = $received;
    }
    expectPosInputRejected(fn () => ($this->start)($input));
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0)
        ->and(PosSimulatorObject::query()->count())->toBe(0)->and(WebsiteInventoryReservation::query()->count())->toBe(0)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
})->with([
    'missing' => [null, false], 'underpaid by one cent' => [2705, true], 'zero' => [0, true],
    'negative' => [-1, true], 'fractional cents' => ['3000.50', true], 'over supported bound' => [100000001, true],
]);

it('rejects cash amounts on card requests without creating an ambiguous sale', function (bool $explicitCard): void {
    $input = [...$this->fixture->input(), 'cash_received_cents' => 3000];
    if ($explicitCard) {
        $input['tender'] = 'card';
    }
    expectPosInputRejected(fn () => ($this->start)($input));
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0)
        ->and(PosSimulatorObject::query()->count())->toBe(0)->and(WebsiteInventoryReservation::query()->count())->toBe(0);
})->with(['default card' => [false], 'explicit card' => [true]]);

it('rejects new cash admission when the provider supports only card without writing a sale', function (bool $declared): void {
    $capabilities = ['environment' => 'simulation', 'physical_readers' => false, 'offline' => false, 'partial_refunds' => true];
    if ($declared) {
        $capabilities['tenders'] = ['card'];
    }
    $reader = posReaderMock('simulated_reader_v1', $capabilities);
    $reader->shouldNotReceive('createPayment');
    $reader->shouldNotReceive('payment');
    $reader->shouldNotReceive('findPayment');
    app()->instance(SimulatedReader::class, $reader);
    expectPosInputRejected(fn () => app(PosService::class)->start($this->fixture->tenant, $this->fixture->cashier,
        $this->fixture->register->id, [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000]));
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0)
        ->and(PosSimulatorObject::query()->count())->toBe(0)->and(WebsiteInventoryReservation::query()->count())->toBe(0)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0);
})->with(['explicit card-only capability' => [true], 'legacy missing tender capability' => [false]]);

it('services an existing cash sale through its original provider after new tender capabilities narrow', function (): void {
    $input = [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000];
    $attempt = ($this->start)($input);
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock('simulated_reader_v1', ['environment' => 'simulation', 'physical_readers' => false,
        'offline' => false, 'partial_refunds' => true, 'tenders' => ['card']]);
    $reader->shouldNotReceive('createPayment');
    $reader->shouldReceive('createRefund')->once()->andReturnUsing(fn (array $snapshot, string $key) => $concrete->createRefund($snapshot, $key));
    $reader->shouldReceive('findRefund')->andReturnUsing(fn (int $tenant, string $key) => $concrete->findRefund($tenant, $key));
    $reader->shouldReceive('refund')->andReturnUsing(fn (int $tenant, string $id) => $concrete->refund($tenant, $id));
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    $replay = $service->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input);
    $refund = $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional original cash servicing']);
    expect($replay->id)->toBe($attempt->id)->and($replay->status)->toBe('succeeded')
        ->and($refund->status)->toBe('succeeded')->and($refund->snapshot['tender'])->toBe('cash')
        ->and($refund->snapshot['provider_key'])->toBe($attempt->snapshot['provider_key'])
        ->and($attempt->order->fresh()->refunded_cents)->toBe(1000)->and(PosPaymentAttempt::query()->count())->toBe(1)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)->and(WebsiteInventoryMovement::query()->count())->toBe(1);
});

it('calculates cash change from server prices and commits a replayed cash sale only once', function (): void {
    $input = [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000,
        'total_cents' => 1, 'tax_cents' => 0, 'change_cents' => 2999];
    $attempt = ($this->start)($input);
    $again = ($this->start)($input);
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->status)->toBe('succeeded')->and($again->id)->toBe($attempt->id)
        ->and($attempt->order->total_cents)->toBe(2706)->and($attempt->order->tax_cents)->toBe(206)
        ->and($attempt->snapshot['tender'])->toBe('cash')->and($attempt->snapshot['cash_received_cents'])->toBe(3000)
        ->and($attempt->snapshot['change_cents'])->toBe(294)
        ->and(PosPaymentAttempt::query()->count())->toBe(1)->and(WebsiteOrder::query()->count())->toBe(1)
        ->and(PosSimulatorObject::query()->count())->toBe(1)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(WebsiteInventoryMovement::query()->where('reason', 'pos_simulation_sale')->count())->toBe(1)
        ->and(WebsiteOrderEvent::query()->where('event_type', 'pos_simulation_paid')->count())->toBe(1);
    $canonical = PosSimulatorObject::query()->sole()->payload;
    expect($canonical['tender'])->toBe('cash')->and($canonical['amount_cents'])->toBe(2706)
        ->and($canonical['cash_received_cents'])->toBe(3000)->and($canonical['change_cents'])->toBe(294);
});

it('rejects a changed tender or cash received amount under an existing checkout key', function (string $change): void {
    $input = $this->fixture->input();
    if ($change !== 'card to cash') {
        $input = [...$input, 'tender' => 'cash', 'cash_received_cents' => 3000];
    }
    $attempt = ($this->start)($input);
    $changed = match ($change) {
        'card to cash' => [...$input, 'tender' => 'cash', 'cash_received_cents' => 3000],
        'cash to card' => ['request_id' => $input['request_id'], 'lines' => $input['lines'], 'tender' => 'card'],
        'cash received' => [...$input, 'cash_received_cents' => 3500],
    };
    expectPosConflict(fn () => ($this->start)($changed));
    expect(PosPaymentAttempt::query()->count())->toBe(1)->and(PosSimulatorObject::query()->count())->toBe(1)
        ->and($attempt->fresh()->snapshot['tender'])->toBe($change === 'card to cash' ? 'card' : 'cash')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe($change === 'card to cash' ? 5 : 4)
        ->and(WebsiteInventoryMovement::query()->count())->toBe($change === 'card to cash' ? 0 : 1);
})->with(['card to cash', 'cash to card', 'cash received']);

it('refunds a cash sale through its original tender without duplicating refunds or restocking', function (): void {
    $attempt = ($this->start)([...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000]);
    $input = ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional cash return review'];
    $refund = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id, $input);
    $again = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id, $input);
    expect($refund->status)->toBe('succeeded')->and($again->id)->toBe($refund->id)
        ->and($refund->snapshot['tender'])->toBe('cash')->and($refund->snapshot['provider_key'])->toBe($attempt->snapshot['provider_key'])
        ->and($refund->snapshot['payment_id'])->toBe($attempt->provider_id)
        ->and($attempt->order->fresh()->refunded_cents)->toBe(1000)->and(PosRefund::query()->count())->toBe(1);
    expectPosConflict(fn () => $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1707, 'reason' => 'Fictional cash excess refund']));
    $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1706, 'reason' => 'Fictional remaining cash refund']);
    expect($attempt->order->fresh()->refunded_cents)->toBe(2706)->and($attempt->order->fresh()->payment_status)->toBe('refunded')
        ->and(PosRefund::query()->count())->toBe(2)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(1);
});

it('quarantines a canonical tender mismatch before committing stock', function (string $tender): void {
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock();
    $reader->shouldReceive('createPayment')->once()->andReturnUsing(function (array $snapshot, string $key) use ($concrete, $tender): array {
        $canonical = $concrete->createPayment($snapshot, $key);

        return [...$canonical, 'status' => 'succeeded', 'tender' => $tender === 'cash' ? 'card' : 'cash'];
    });
    $reader->shouldNotReceive('payment');
    $reader->shouldNotReceive('findPayment');
    app()->instance(SimulatedReader::class, $reader);
    $input = [...$this->fixture->input(), 'tender' => $tender];
    if ($tender === 'cash') {
        $input['cash_received_cents'] = 3000;
    }
    $attempt = app(PosService::class)->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input);
    expect($attempt->status)->toBe('review')->and($attempt->order->payment_status)->toBe('payment_review')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0)->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
})->with(['card', 'cash']);

it('services legacy card snapshots without changing their original request or payment evidence', function (): void {
    $input = $this->fixture->input();
    $attempt = ($this->start)($input);
    $snapshot = $attempt->snapshot;
    unset($snapshot['tender'], $snapshot['cash_received_cents'], $snapshot['change_cents'], $snapshot['website_customer_id'], $snapshot['customer_snapshot']);
    $legacyHash = hash('sha256', json_encode(['register_id' => $this->fixture->register->id,
        'lines' => [['variant_id' => $this->fixture->variant->id, 'quantity' => 1]]], JSON_THROW_ON_ERROR));
    $attempt->update(['snapshot' => $snapshot, 'request_hash' => $legacyHash]);
    $attempt->order->update(['customer_snapshot' => ['name' => 'Simulated counter sale']]);
    $object = PosSimulatorObject::query()->sole();
    $canonical = $object->payload;
    unset($canonical['tender'], $canonical['cash_received_cents'], $canonical['change_cents'], $canonical['website_customer_id'], $canonical['customer_snapshot']);
    $object->update(['payload' => $canonical]);
    $again = ($this->start)($input);
    expect($again->id)->toBe($attempt->id)->and($again->snapshot)->toBe($snapshot)->and($again->request_hash)->toBe($legacyHash);
    $customer = posNativeCustomer($this->fixture);
    expectPosConflict(fn () => ($this->start)([...$input, 'website_customer_id' => $customer->id]));
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    $refund = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional legacy card refund']);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($attempt->fresh()->snapshot)->toBe($snapshot)
        ->and($refund->status)->toBe('succeeded')->and($refund->snapshot['tender'])->toBe('card')
        ->and($refund->snapshot['provider_key'])->toBe($snapshot['provider_key'])
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)->and(WebsiteInventoryMovement::query()->count())->toBe(1);
});

it('cannot open the cash demonstration in production even when the simulation flag is set', function (): void {
    app()->instance('env', 'production');
    try {
        ($this->start)([...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000]);
        test()->fail('Cash demonstration must remain unavailable in production.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    } finally {
        app()->instance('env', 'testing');
    }
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0)
        ->and(PosSimulatorObject::query()->count())->toBe(0)->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('rejects ambiguous customer identifiers and unavailable native customer selections before payment writes', function (string $field, string $target): void {
    $otherTenant = Tenant::query()->create(['name' => 'Fictional unrelated customer tenant', 'slug' => 'pos-customer-foreign']);
    $ownCustomer = WebsiteCustomer::query()->create(['tenant_id' => $this->fixture->tenant->id,
        'email' => 'own-pos-customer@example.test', 'first_name' => 'Fictional local shopper', 'status' => 'active']);
    $foreignCustomer = WebsiteCustomer::query()->create(['tenant_id' => $otherTenant->id,
        'email' => 'foreign-pos-customer@example.test', 'first_name' => 'Fictional foreign shopper', 'status' => 'active']);
    $inactiveCustomer = WebsiteCustomer::query()->create(['tenant_id' => $this->fixture->tenant->id,
        'email' => 'inactive-pos-customer@example.test', 'first_name' => 'Fictional inactive shopper', 'status' => 'inactive']);
    $originalCustomers = WebsiteCustomer::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $customerId = match ($target) {
        'own' => $ownCustomer->id, 'foreign' => $foreignCustomer->id, 'inactive' => $inactiveCustomer->id, 'nonexistent' => $foreignCustomer->id + 1000,
    };
    try {
        ($this->start)([...$this->fixture->input(), $field => $customerId]);
        test()->fail('The supplied customer selection must not be accepted.');
    } catch (ValidationException $exception) {
        expect($field)->toBe('customer_id')->and($exception->errors())->toHaveKey($field);
    } catch (ModelNotFoundException $exception) {
        expect($field)->toBe('website_customer_id')->and($exception->getModel())->toBe(WebsiteCustomer::class);
    }
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0)
        ->and(WebsiteInventoryReservation::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(0)
        ->and($ownCustomer->orders()->count())->toBe(0)->and($foreignCustomer->orders()->count())->toBe(0)
        ->and(WebsiteCustomer::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($originalCustomers)
        ->and(MarketingProfile::query()->count())->toBe(0)->and(MarketingConsentEvent::query()->count())->toBe(0)
        ->and(MarketingConsentRequest::query()->count())->toBe(0);
})->with([
    'local customer_id' => ['customer_id', 'own'], 'foreign customer_id' => ['customer_id', 'foreign'],
    'unknown customer_id' => ['customer_id', 'nonexistent'], 'inactive website_customer_id' => ['website_customer_id', 'inactive'],
    'foreign website_customer_id' => ['website_customer_id', 'foreign'], 'unknown website_customer_id' => ['website_customer_id', 'nonexistent'],
]);

it('binds an explicitly selected active native customer while preserving payment verification and consent boundaries', function (): void {
    $customer = posNativeCustomer($this->fixture);
    $original = $customer->fresh()->getRawOriginal();
    $input = [...$this->fixture->input(), 'website_customer_id' => $customer->id,
        'customer_snapshot' => ['name' => 'Untrusted replacement name', 'email' => 'forged@example.test']];
    $attempt = ($this->start)($input);
    $contact = ['name' => 'Casey Fictional one', 'email' => 'casey-one@example.test', 'phone' => '+12025550123'];
    expect($attempt->snapshot['website_customer_id'])->toBe($customer->id)
        ->and($attempt->snapshot['customer_snapshot'])->toBe($contact)
        ->and($attempt->order->website_customer_id)->toBe($customer->id)->and($attempt->order->customer_snapshot)->toBe($contact)
        ->and($attempt->snapshot['site_id'])->toBe($this->fixture->register->tenant_site_id)
        ->and($customer->orders()->whereNotNull('paid_at')->count())->toBe(0)
        ->and($attempt->order->paid_at)->toBeNull()->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
    expect(json_encode($contact, JSON_THROW_ON_ERROR))->not->toContain('staff-only')->not->toContain('forged@example.test');
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($customer->orders()->whereNotNull('paid_at')->count())->toBe(0)
        ->and(WebsiteOrder::query()->forTenantId($this->fixture->tenant->id)->where('website_customer_id', $customer->id)
            ->where('payment_status', 'paid')->sole()->id)->toBe($attempt->website_order_id)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)->and(WebsiteInventoryMovement::query()->count())->toBe(1)
        ->and($customer->fresh()->getRawOriginal())->toBe($original)->and(WebsiteCustomer::query()->count())->toBe(1)
        ->and(MarketingProfile::query()->count())->toBe(0)->and(MarketingConsentEvent::query()->count())->toBe(0)
        ->and(MarketingConsentRequest::query()->count())->toBe(0);
});

it('keeps customer choice immutable under the original checkout request', function (string $change): void {
    $firstCustomer = posNativeCustomer($this->fixture);
    $secondCustomer = posNativeCustomer($this->fixture, 'two');
    $input = $this->fixture->input();
    if ($change !== 'guest to customer') {
        $input['website_customer_id'] = $firstCustomer->id;
    }
    $attempt = ($this->start)($input);
    $changed = [...$input, 'website_customer_id' => match ($change) {
        'guest to customer' => $firstCustomer->id, 'customer to guest' => null, 'customer to customer' => $secondCustomer->id,
    }];
    expectPosConflict(fn () => ($this->start)($changed));
    expect($attempt->fresh()->snapshot['website_customer_id'])->toBe($change === 'guest to customer' ? null : $firstCustomer->id)
        ->and($attempt->order->fresh()->website_customer_id)->toBe($change === 'guest to customer' ? null : $firstCustomer->id)
        ->and(PosPaymentAttempt::query()->count())->toBe(1)->and(PosSimulatorObject::query()->count())->toBe(1)
        ->and(WebsiteInventoryReservation::query()->count())->toBe(1)->and(WebsiteInventoryMovement::query()->count())->toBe(0);
})->with(['guest to customer', 'customer to guest', 'customer to customer']);

it('retains the original customer receipt snapshot after contact edits or inactivation and services the same sale', function (): void {
    $customer = posNativeCustomer($this->fixture);
    $input = [...$this->fixture->input(), 'website_customer_id' => $customer->id];
    $attempt = ($this->start)($input);
    $frozen = $attempt->snapshot;
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    $customer->update(['first_name' => 'Changed', 'last_name' => 'Contact', 'email' => 'changed@example.test', 'phone' => '+12025550199', 'status' => 'inactive']);
    $replay = ($this->start)($input);
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->manager, $attempt->id);
    $refund = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional identified customer return']);
    expect($replay->id)->toBe($attempt->id)->and($replay->snapshot)->toBe($frozen)
        ->and($attempt->order->fresh()->website_customer_id)->toBe($customer->id)
        ->and($attempt->order->fresh()->customer_snapshot)->toBe($frozen['customer_snapshot'])
        ->and($refund->status)->toBe('succeeded')->and($attempt->order->fresh()->refunded_cents)->toBe(500)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(MarketingProfile::query()->count())->toBe(0)->and(MarketingConsentEvent::query()->count())->toBe(0);
});

it('rejects changes to the saved customer or site binding before reconciling a payment', function (string $field): void {
    $customer = posNativeCustomer($this->fixture);
    $other = posNativeCustomer($this->fixture, 'two');
    $attempt = ($this->start)([...$this->fixture->input(), 'website_customer_id' => $customer->id]);
    if ($field === 'customer') {
        $attempt->order->update(['website_customer_id' => $other->id]);
    } elseif ($field === 'contact snapshot') {
        $attempt->order->update(['customer_snapshot' => ['name' => 'Replacement contact']]);
    } else {
        $foreignTenant = Tenant::query()->create(['name' => 'Fictional foreign site owner', 'slug' => 'pos-foreign-site-owner']);
        $site = TenantSite::query()->create(['tenant_id' => $foreignTenant->id, 'subdomain' => 'other-pos-customer-site', 'status' => 'draft', 'settings' => []]);
        $attempt->order->update(['tenant_site_id' => $site->id]);
    }
    if ($field === 'site') {
        expect(fn () => $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id))->toThrow(ModelNotFoundException::class);
    } else {
        expectPosConflict(fn () => $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id));
    }
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(WebsiteInventoryMovement::query()->count())->toBe(0)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
})->with(['customer', 'contact snapshot', 'site']);

it('replays a pre-customer card request unchanged instead of retroactively attaching a selected customer', function (): void {
    $input = $this->fixture->input();
    $attempt = ($this->start)($input);
    $snapshot = $attempt->snapshot;
    unset($snapshot['website_customer_id'], $snapshot['customer_snapshot']);
    $legacyHash = hash('sha256', json_encode(['register_id' => $this->fixture->register->id,
        'lines' => [['variant_id' => $this->fixture->variant->id, 'quantity' => 1]],
        'tender' => 'card', 'cash_received_cents' => null, 'change_cents' => null], JSON_THROW_ON_ERROR));
    $attempt->update(['snapshot' => $snapshot, 'request_hash' => $legacyHash]);
    $attempt->order->update(['customer_snapshot' => ['name' => 'Simulated counter sale']]);
    $object = PosSimulatorObject::query()->sole();
    $canonical = $object->payload;
    unset($canonical['website_customer_id'], $canonical['customer_snapshot']);
    $object->update(['payload' => $canonical]);
    $replay = ($this->start)($input);
    expect($replay->id)->toBe($attempt->id)->and($replay->snapshot)->toBe($snapshot)->and($replay->request_hash)->toBe($legacyHash);
    $customer = posNativeCustomer($this->fixture);
    expectPosConflict(fn () => ($this->start)([...$input, 'website_customer_id' => $customer->id]));
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    expect($attempt->fresh()->status)->toBe('succeeded')->and($attempt->order->fresh()->website_customer_id)->toBeNull()
        ->and($customer->orders()->count())->toBe(0)->and($attempt->fresh()->snapshot)->toBe($snapshot);
});

it('keeps guest sales unlinked and records no paid history before canonical success or marketing consent after it', function (): void {
    $attempt = ($this->start)();
    expect($attempt->order->website_customer_id)->toBeNull()->and($attempt->order->paid_at)->toBeNull()
        ->and($attempt->order->payment_status)->toBe('pending')
        ->and(WebsiteOrderEvent::query()->where('event_type', 'pos_simulation_paid')->count())->toBe(0);
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'unknown');
    expect($attempt->fresh()->status)->toBe('unknown')->and($attempt->order->fresh()->website_customer_id)->toBeNull()
        ->and($attempt->order->fresh()->paid_at)->toBeNull()->and($attempt->order->fresh()->payment_status)->toBe('pending')
        ->and(WebsiteOrderEvent::query()->where('event_type', 'pos_simulation_paid')->count())->toBe(0);
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($attempt->order->fresh()->payment_status)->toBe('paid')
        ->and($attempt->order->fresh()->paid_at)->not->toBeNull()->and($attempt->order->fresh()->website_customer_id)->toBeNull()
        ->and(WebsiteOrderEvent::query()->where('event_type', 'pos_simulation_paid')->count())->toBe(1)
        ->and(WebsiteOrder::query()->whereNotNull('website_customer_id')->count())->toBe(0)
        ->and(WebsiteCustomer::query()->count())->toBe(0)->and(MarketingProfile::query()->count())->toBe(0)
        ->and(MarketingConsentEvent::query()->count())->toBe(0)->and(MarketingConsentRequest::query()->count())->toBe(0);
});

it('replays the same frozen attempt after a price changes and rejects a changed request', function (): void {
    $input = $this->fixture->input();
    $first = ($this->start)($input);
    $this->fixture->variant->update(['price_cents' => 9900]);
    $again = ($this->start)($input);
    expect($again->id)->toBe($first->id)->and($again->order->total_cents)->toBe(2706)
        ->and(PosPaymentAttempt::query()->count())->toBe(1)->and(WebsiteOrder::query()->count())->toBe(1)
        ->and(PosSimulatorObject::query()->count())->toBe(1)->and(WebsiteInventoryReservation::query()->count())->toBe(1);
    $input['lines'][0]['quantity'] = 2;
    expectPosConflict(fn () => ($this->start)($input));
    expect(PosPaymentAttempt::query()->count())->toBe(1)->and(WebsiteOrder::query()->count())->toBe(1);
});

it('serializes an unresolved register and preserves stock when the reader outcome is unknown', function (): void {
    $attempt = ($this->start)();
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'unknown');
    expectPosConflict(fn () => ($this->start)());
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('unknown')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active')
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0);
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(WebsiteInventoryMovement::query()->where('reason', 'pos_simulation_sale')->count())->toBe(1);
});

it('respects an existing website hold when selling the last catalog item', function (): void {
    $this->fixture->variant->update(['inventory_quantity' => 1]);
    $websiteOrder = $this->fixture->websiteOrder();
    WebsiteInventoryReservation::query()->create(['tenant_id' => $this->fixture->tenant->id,
        'website_product_variant_id' => $this->fixture->variant->id, 'website_order_id' => $websiteOrder->id,
        'quantity' => 1, 'status' => 'active', 'expires_at' => now()->addMinutes(30)]);
    expectPosConflict(fn () => ($this->start)());
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(0)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(1)
        ->and(WebsiteInventoryReservation::query()->count())->toBe(1);
});

it('fails closed for missing tracked stock instead of turning it into unlimited inventory', function (): void {
    $this->fixture->variant->update(['inventory_quantity' => null]);
    $catalogItem = $this->pos->catalog($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id)->sole();
    expect($catalogItem['available_quantity'])->toBe(0);
    try {
        ($this->start)();
        test()->fail('A tracked product without stock evidence must not start a reader payment.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }
    expect(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0)
        ->and(WebsiteInventoryReservation::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(0);
});

it('allows explicitly untracked physical products without inventing stock movements', function (): void {
    $this->fixture->product->update(['track_inventory' => false]);
    $this->fixture->variant->update(['inventory_quantity' => null]);
    $catalogItem = $this->pos->catalog($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id)->sole();
    expect($catalogItem['available_quantity'])->toBeNull();
    $attempt = ($this->paid)(2);
    expect($attempt->status)->toBe('succeeded')->and($attempt->order->payment_status)->toBe('paid')
        ->and($attempt->order->total_cents)->toBe(5413)->and($this->fixture->variant->fresh()->inventory_quantity)->toBeNull()
        ->and(WebsiteInventoryReservation::query()->count())->toBe(0)->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('commits one sale once and keeps issued line and tax snapshots after catalog edits', function (): void {
    $attempt = ($this->paid)(2);
    $this->fixture->variant->update(['price_cents' => 9900, 'title' => 'Changed catalog description']);
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($attempt->fresh()->stock_committed_at)->not->toBeNull()
        ->and($attempt->order->fresh()->payment_status)->toBe('paid')
        ->and($attempt->order->fresh()->total_cents)->toBe(5413)
        ->and($attempt->order->lines()->sole()->unit_price_cents)->toBe(2500)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3)
        ->and(WebsiteInventoryMovement::query()->where('reason', 'pos_simulation_sale')->count())->toBe(1);
});

it('releases only a confirmed failed or cancelled payment hold', function (string $outcome, string $status): void {
    $attempt = ($this->start)();
    $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, $outcome);
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe($status)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->not->toBe('active')
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0);
    expect(($this->start)()->id)->not->toBe($attempt->id);
})->with([['declined', 'failed'], ['cancelled', 'cancelled']]);

it('reconciles a reader success that beats a browser cancellation instead of releasing the sale', function (): void {
    $attempt = ($this->start)();
    app(SimulatedReader::class)->simulatePayment($this->fixture->tenant->id, $attempt->provider_id, 'approved');
    $this->pos->cancel($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(WebsiteInventoryMovement::query()->where('reason', 'pos_simulation_sale')->count())->toBe(1);
});

it('quarantines canonical evidence that does not match the frozen payment', function (string $field, mixed $value): void {
    $attempt = ($this->start)();
    $object = PosSimulatorObject::query()->sole();
    $object->update(['payload' => [...$object->payload, 'status' => 'succeeded', $field => $value]]);
    $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('review')->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
    expectPosConflict(fn () => ($this->start)());
})->with([
    'amount' => ['amount_cents', 1], 'currency' => ['currency', 'eur'], 'merchant' => ['merchant_id', 'sim_foreign_merchant'],
    'reader' => ['reader_id', 'sim_foreign_reader'], 'location' => ['location_id', 999999],
    'tenant' => ['tenant_id', 999999], 'mode' => ['environment', 'live'], 'nonce' => ['nonce', 'forged'],
]);

it('denies foreign tenant register variant payment and refund references', function (): void {
    $other = PosFixture::merchant('other');
    expect(fn () => $this->pos->start($this->fixture->tenant, $this->fixture->cashier, $other->register->id, $this->fixture->input()))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => ($this->start)($other->input()))->toThrow(ModelNotFoundException::class);
    $foreign = $this->pos->start($other->tenant, $other->cashier, $other->register->id, $other->input());
    expect(fn () => $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $foreign->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $foreign->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 100, 'reason' => 'Fictional correction']))->toThrow(ModelNotFoundException::class);
    expect(PosPaymentAttempt::query()->count())->toBe(1)->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('rechecks active cashier assignment and does not give platform operators tenant payment authority', function (): void {
    $attempt = ($this->start)();
    $this->fixture->register->update(['cashier_user_ids' => []]);
    expect(fn () => $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved'))->toThrow(HttpException::class);
    $operator = User::factory()->platformAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $this->pos->reconcile($this->fixture->tenant, $operator, $attempt->id))->toThrow(HttpException::class);
    $this->fixture->manager->tenants()->updateExistingPivot($this->fixture->tenant->id, ['membership_active' => false]);
    expect(fn () => $this->pos->reconcile($this->fixture->tenant, $this->fixture->manager, $attempt->id))->toThrow(HttpException::class);
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('keeps simulation inaccessible when disabled unallowlisted unentitled or in production', function (string $gate): void {
    match ($gate) {
        'switch' => config()->set('pos.enabled', false),
        'allowlist' => config()->set('pos.tenant_ids', []),
        'string allowlist' => config()->set('pos.tenant_ids', [(string) $this->fixture->tenant->id]),
        'entitlement' => TenantModuleEntitlement::query()->where('tenant_id', $this->fixture->tenant->id)->update(['enabled_status' => 'disabled']),
        'production' => app()->instance('env', 'production'),
    };
    try {
        expect(fn () => app(PosAccess::class)->register($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id))->toThrow(HttpException::class);
        expect(PosPaymentAttempt::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
})->with(['switch', 'allowlist', 'string allowlist', 'entitlement', 'production']);

it('requires the original register binding and immutable sale terms during recovery', function (): void {
    $attempt = ($this->start)();
    $originalMerchant = $this->fixture->register->merchant_id;
    $this->fixture->register->update(['merchant_id' => 'sim_replacement_merchant']);
    expectPosConflict(fn () => $this->pos->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved'));
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(WebsiteInventoryMovement::query()->count())->toBe(0);
    $this->fixture->register->update(['merchant_id' => $originalMerchant]);
    $attempt->order->update(['total_cents' => 1]);
    expectPosConflict(fn () => $this->pos->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id));
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('freezes the installed provider for the sale and its original-payment refund', function (): void {
    $attempt = ($this->paid)();
    $refund = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional original-provider refund']);
    expect($attempt->snapshot['provider_key'])->toBe('simulated_reader_v1')
        ->and($refund->snapshot['provider_key'])->toBe($attempt->snapshot['provider_key']);
    foreach (PosSimulatorObject::query()->get() as $object) {
        expect($object->payload['provider_key'])->toBe('simulated_reader_v1');
    }
});

it('refuses a different installed adapter before payment controls or refund dispatch', function (): void {
    $attempt = ($this->start)();
    $reader = posReaderMock('different_simulator_v2');
    foreach (['createPayment', 'payment', 'findPayment', 'cancelPayment', 'simulatePayment', 'createRefund', 'findRefund', 'refund'] as $method) {
        $reader->shouldNotReceive($method);
    }
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    foreach ([
        fn () => $service->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id),
        fn () => $service->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved'),
        fn () => $service->cancel($this->fixture->tenant, $this->fixture->cashier, $attempt->id),
        fn () => $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
            ['request_id' => (string) Str::uuid(), 'amount_cents' => 100, 'reason' => 'Fictional wrong-provider attempt']),
    ] as $operation) {
        expectPosConflict($operation);
    }
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(PosRefund::query()->count())->toBe(0)
        ->and(PosSimulatorObject::query()->count())->toBe(1)->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('rejects a changed frozen provider instead of selecting a fallback', function (): void {
    $attempt = ($this->start)();
    $attempt->update(['snapshot' => [...$attempt->snapshot, 'provider_key' => 'different_simulator_v2']]);
    $reader = posReaderMock();
    $reader->shouldNotReceive('payment');
    $reader->shouldNotReceive('findPayment');
    app()->instance(SimulatedReader::class, $reader);
    expectPosConflict(fn () => app(PosService::class)->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id));
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(WebsiteInventoryMovement::query()->count())->toBe(0);
});

it('keeps a payment quarantined when an earlier provider read returns after a competing review', function (): void {
    $attempt = ($this->start)();
    $canonical = app(DatabaseSimulatedReader::class)->simulatePayment($this->fixture->tenant->id, $attempt->provider_id, 'approved');
    $reader = posReaderMock();
    $reader->shouldReceive('payment')->once()->andReturnUsing(function () use ($attempt, $canonical): array {
        $attempt->update(['status' => 'review', 'last_error' => 'competing_reconciliation_review']);

        return $canonical;
    });
    app()->instance(SimulatedReader::class, $reader);
    app(PosService::class)->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->status)->toBe('review')->and(WebsiteInventoryMovement::query()->count())->toBe(0)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
});

it('requires manager permission for a bounded replay-safe partial refund and never restocks automatically', function (): void {
    $attempt = ($this->paid)();
    $input = ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional partial return review'];
    expect(fn () => $this->pos->refund($this->fixture->tenant, $this->fixture->cashier, $attempt->id, $input))->toThrow(HttpException::class);
    $refund = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id, $input);
    $again = $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id, $input);
    expect($refund->status)->toBe('succeeded')->and($again->id)->toBe($refund->id)->and(PosRefund::query()->count())->toBe(1)
        ->and($attempt->order->fresh()->refunded_cents)->toBe(1000)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(WebsiteInventoryMovement::query()->count())->toBe(1);
    expectPosConflict(fn () => $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id, [...$input, 'amount_cents' => 900]));
    expectPosConflict(fn () => $this->pos->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1707, 'reason' => 'Fictional over-refund attempt']));
    expect($attempt->order->fresh()->refunded_cents)->toBe(1000);
});

it('honors provider refund capabilities before dispatch while retaining full-refund servicing', function (): void {
    $attempt = ($this->paid)();
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock('simulated_reader_v1', ['environment' => 'simulation', 'physical_readers' => false, 'offline' => false, 'partial_refunds' => false]);
    $reader->shouldReceive('createRefund')->once()->andReturnUsing(fn (array $snapshot, string $key) => $concrete->createRefund($snapshot, $key));
    $reader->shouldReceive('findRefund')->andReturnUsing(fn (int $tenant, string $key) => $concrete->findRefund($tenant, $key));
    $reader->shouldReceive('refund')->andReturnUsing(fn (int $tenant, string $id) => $concrete->refund($tenant, $id));
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    try {
        $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
            ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional unsupported partial refund']);
        test()->fail('Unsupported partial refunds must not be dispatched.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }
    expect(PosRefund::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(1);
    $refund = $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => $attempt->snapshot['amount_cents'], 'reason' => 'Fictional full original refund']);
    expect($refund->status)->toBe('succeeded')->and($attempt->order->fresh()->payment_status)->toBe('refunded')
        ->and($attempt->order->fresh()->refunded_cents)->toBe($attempt->snapshot['amount_cents'])
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4);
});

it('recovers a lost create response by the saved key without creating a replacement payment', function (): void {
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock();
    $reader->shouldReceive('createPayment')->once()->andReturnUsing(function (array $snapshot, string $key) use ($concrete): array {
        $concrete->createPayment($snapshot, $key);
        throw new RuntimeException('Fictional response lost after reader creation');
    });
    $reader->shouldReceive('findPayment')->andReturnUsing(fn (int $tenant, string $key) => $concrete->findPayment($tenant, $key));
    $reader->shouldReceive('payment')->andReturnUsing(fn (int $tenant, string $id) => $concrete->payment($tenant, $id));
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    $input = $this->fixture->input();
    $attempt = $service->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input);
    expect($attempt->status)->toBe('unknown')->and(PosPaymentAttempt::query()->count())->toBe(1)->and(PosSimulatorObject::query()->count())->toBe(1);
    $service->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($attempt->fresh()->provider_id)->not->toBeNull()->and($attempt->fresh()->status)->toBe('awaiting_reader')
        ->and(PosPaymentAttempt::query()->count())->toBe(1)->and(PosSimulatorObject::query()->count())->toBe(1)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active');
});

it('recovers an uncertain cash success without cancelling a reader or creating a replacement sale', function (): void {
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock();
    $reader->shouldReceive('createPayment')->once()->andReturnUsing(function (array $snapshot, string $key) use ($concrete): array {
        $concrete->createPayment($snapshot, $key);
        throw new RuntimeException('Fictional cash response lost after canonical success');
    });
    $reader->shouldReceive('findPayment')->once()->andReturnUsing(fn (int $tenant, string $key) => $concrete->findPayment($tenant, $key));
    $reader->shouldNotReceive('simulatePayment');
    $reader->shouldNotReceive('cancelPayment');
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    $input = [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000];
    $attempt = $service->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input);
    expect($attempt->status)->toBe('unknown')->and($attempt->provider_id)->toBeNull()
        ->and(PosSimulatorObject::query()->sole()->payload['status'])->toBe('succeeded')
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5)
        ->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('active')
        ->and(WebsiteInventoryMovement::query()->count())->toBe(0);
    expectPosConflict(fn () => $service->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id,
        [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000]));
    expectPosInputRejected(fn () => $service->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved'));
    expect($attempt->fresh()->status)->toBe('unknown')->and(PosPaymentAttempt::query()->count())->toBe(1);
    $recovered = $service->cancel($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    $replay = $service->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input);
    $service->reconcile($this->fixture->tenant, $this->fixture->cashier, $attempt->id);
    expect($recovered->status)->toBe('succeeded')->and($replay->id)->toBe($attempt->id)->and($replay->status)->toBe('succeeded')
        ->and($recovered->snapshot['tender'])->toBe('cash')->and($recovered->snapshot['change_cents'])->toBe(294)
        ->and($recovered->order->payment_status)->toBe('paid')->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)
        ->and(PosPaymentAttempt::query()->count())->toBe(1)->and(WebsiteOrder::query()->count())->toBe(1)
        ->and(PosSimulatorObject::query()->count())->toBe(1)->and(WebsiteInventoryReservation::query()->sole()->status)->toBe('confirmed')
        ->and(WebsiteInventoryMovement::query()->where('reason', 'pos_simulation_sale')->count())->toBe(1)
        ->and(WebsiteOrderEvent::query()->where('event_type', 'pos_simulation_paid')->count())->toBe(1);
});

it('reserves an uncertain refund and reconciles the original canonical refund once', function (): void {
    $attempt = ($this->paid)();
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock();
    $reader->shouldReceive('createRefund')->once()->andReturnUsing(function (array $snapshot, string $key) use ($concrete): array {
        $concrete->createRefund($snapshot, $key);
        throw new RuntimeException('Fictional refund response lost');
    });
    $reader->shouldReceive('findRefund')->andReturnUsing(fn (int $tenant, string $key) => $concrete->findRefund($tenant, $key));
    $reader->shouldReceive('refund')->andReturnUsing(fn (int $tenant, string $id) => $concrete->refund($tenant, $id));
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    $input = ['request_id' => (string) Str::uuid(), 'amount_cents' => 2000, 'reason' => 'Fictional uncertain refund'];
    $refund = $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id, $input);
    expect($refund->status)->toBe('unknown')->and($attempt->order->fresh()->refunded_cents)->toBe(0);
    expectPosConflict(fn () => $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional excess while pending']));
    $service->reconcileRefund($this->fixture->tenant, $this->fixture->manager, $refund->id);
    $service->reconcileRefund($this->fixture->tenant, $this->fixture->manager, $refund->id);
    expect($refund->fresh()->status)->toBe('succeeded')->and($attempt->order->fresh()->refunded_cents)->toBe(2000)
        ->and(PosRefund::query()->count())->toBe(1)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4);
});

it('keeps a refund quarantined when an earlier canonical read completes after a competing review', function (): void {
    $attempt = ($this->paid)();
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = posReaderMock();
    $reader->shouldReceive('createRefund')->once()->andReturnUsing(function (array $snapshot, string $key) use ($concrete): array {
        $concrete->createRefund($snapshot, $key);
        throw new RuntimeException('Fictional refund response lost');
    });
    $reader->shouldReceive('findRefund')->once()->andReturnUsing(function (int $tenant, string $key) use ($concrete): ?array {
        $canonical = $concrete->findRefund($tenant, $key);
        PosRefund::query()->where('tenant_id', $tenant)->where('request_id', $key)->update(['status' => 'review', 'last_error' => 'competing_reconciliation_review']);

        return $canonical;
    });
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    $refund = $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional concurrent review']);
    $service->reconcileRefund($this->fixture->tenant, $this->fixture->manager, $refund->id);
    expect($refund->fresh()->status)->toBe('review')->and($attempt->order->fresh()->refunded_cents)->toBe(0)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4);
});

it('rejects a changed refund provider before looking up an uncertain original refund', function (): void {
    $attempt = ($this->paid)();
    $reader = posReaderMock();
    $reader->shouldReceive('createRefund')->once()->andThrow(new RuntimeException('Fictional response unavailable'));
    $reader->shouldNotReceive('findRefund');
    $reader->shouldNotReceive('refund');
    app()->instance(SimulatedReader::class, $reader);
    $service = app(PosService::class);
    $refund = $service->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional uncertain original refund']);
    $refund->update(['snapshot' => [...$refund->snapshot, 'provider_key' => 'different_simulator_v2']]);
    expectPosConflict(fn () => $service->reconcileRefund($this->fixture->tenant, $this->fixture->manager, $refund->id));
    expect($refund->fresh()->status)->toBe('unknown')->and($attempt->order->fresh()->refunded_cents)->toBe(0)
        ->and(PosRefund::query()->count())->toBe(1)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4);
});
