<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use App\Models\PosPaymentAttempt;
use App\Models\PosSimulatorObject;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Services\PointOfSale\DatabaseSimulatedReader;
use App\Services\PointOfSale\PosCustomerDirectory;
use App\Services\PointOfSale\PosService;
use App\Services\PointOfSale\SimulatedReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosFixture;

function posDirectoryContact(array $overrides = []): array
{
    return [...['request_id' => (string) Str::uuid(), 'first_name' => 'Casey', 'last_name' => 'Fictional',
        'email' => 'casey@example.test', 'phone' => '+12025550123'], ...$overrides];
}

function posDirectoryCustomer(PosFixture $fixture, array $overrides = []): WebsiteCustomer
{
    return WebsiteCustomer::query()->create([...['tenant_id' => $fixture->tenant->id, 'first_name' => 'Casey', 'last_name' => 'Fictional',
        'email' => 'casey@example.test', 'phone' => '+12025550123', 'status' => 'active', 'notes' => ['private' => 'Fictional staff-only note']], ...$overrides]);
}

function posDirectoryHttp(Closure $operation, int $status): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe($status);

        return;
    }
    test()->fail('Expected the customer directory operation to be rejected.');
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->fixture = PosFixture::merchant();
    $this->directory = app(PosCustomerDirectory::class);
    $this->sales = app(PosService::class);
    $this->create = fn (array $input) => $this->directory->create($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input);
    $this->paidFor = function (WebsiteCustomer $customer, ?int $registerId = null, string $tender = 'card'): PosPaymentAttempt {
        $input = [...$this->fixture->input(), 'website_customer_id' => $customer->id, 'tender' => $tender];
        if ($tender === 'cash') {
            $input['cash_received_cents'] = 3000;
        }
        $attempt = $this->sales->start($this->fixture->tenant, $this->fixture->cashier, $registerId ?? $this->fixture->register->id, $input);

        return $tender === 'cash' ? $attempt : $this->sales->simulate($this->fixture->tenant, $this->fixture->cashier, $attempt->id, 'approved');
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('creates one native contact per immutable UUID including when email is absent without marketing enrollment', function (bool $email): void {
    $input = posDirectoryContact(['email' => $email ? '  CASEY@EXAMPLE.TEST  ' : null, 'first_name' => ' Casey ']);
    $first = ($this->create)($input);
    $replay = ($this->create)($input);
    expect($first['simulation'])->toBeTrue()->and($replay)->toBe($first)->and(WebsiteCustomer::query()->count())->toBe(1)
        ->and($first['customer']['name'])->toBe('Casey Fictional')->and($first['customer']['email'])->toBe($email ? 'casey@example.test' : null)
        ->and($this->directory->findCreation($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $input['request_id']))->toBe($first['customer']);
    posDirectoryHttp(fn () => ($this->create)([...$input, 'phone' => '+12025550199']), 409);
    expect(WebsiteCustomer::query()->count())->toBe(1)->and(MarketingProfile::query()->count())->toBe(0)
        ->and(MarketingConsentEvent::query()->count())->toBe(0)->and(MarketingConsentRequest::query()->count())->toBe(0)
        ->and(PosPaymentAttempt::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0);
})->with(['with normalized email' => [true], 'without email' => [false]]);

it('rejects a normalized duplicate email without merging or rewriting the existing native contact', function (): void {
    $existing = posDirectoryCustomer($this->fixture, ['email' => 'CASEY@EXAMPLE.TEST']);
    $original = $existing->fresh()->getRawOriginal();
    try {
        ($this->create)(posDirectoryContact(['first_name' => 'Different contact', 'email' => ' casey@example.test ']));
        test()->fail('Duplicate email must instruct explicit selection of the existing native customer.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('email');
    }
    expect(WebsiteCustomer::query()->count())->toBe(1)->and($existing->fresh()->getRawOriginal())->toBe($original)
        ->and(MarketingProfile::query()->count())->toBe(0)->and(MarketingConsentEvent::query()->count())->toBe(0)
        ->and(MarketingConsentRequest::query()->count())->toBe(0);
});

it('searches literal wildcard characters and exposes only a minimal active customer summary', function (): void {
    $literal = posDirectoryCustomer($this->fixture, ['first_name' => 'A%_B', 'email' => 'literal@example.test']);
    posDirectoryCustomer($this->fixture, ['first_name' => 'AXYB', 'email' => 'ordinary@example.test']);
    posDirectoryCustomer($this->fixture, ['first_name' => 'A%_B inactive', 'email' => 'inactive@example.test', 'status' => 'inactive']);
    $before = WebsiteCustomer::query()->count();
    $found = $this->directory->search($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, '%_');
    expect($found['simulation'])->toBeTrue()->and($found['has_more'])->toBeFalse()
        ->and(array_column($found['customers'], 'id'))->toBe([$literal->id])
        ->and(array_keys($found['customers'][0]))->toBe(['id', 'name', 'email', 'phone']);
    expect(json_encode($found, JSON_THROW_ON_ERROR))->not->toContain('staff-only')->not->toContain('notes');
    expect($this->directory->get($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $literal->id))->toBe($found['customers'][0])
        ->and(WebsiteCustomer::query()->count())->toBe($before)->and(MarketingProfile::query()->count())->toBe(0)
        ->and(MarketingConsentEvent::query()->count())->toBe(0)->and(PosSimulatorObject::query()->count())->toBe(0);
});

it('requires a specific printable search and truthfully caps the shown customer matches', function (): void {
    expect(fn () => $this->directory->search($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, 'x'))->toThrow(ValidationException::class);
    expect(fn () => $this->directory->search($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, "Casey\nFictional"))->toThrow(ValidationException::class);
    foreach (range(1, 22) as $index) {
        posDirectoryCustomer($this->fixture, ['first_name' => 'Fictional Match '.sprintf('%02d', $index), 'email' => 'match-'.$index.'@example.test']);
    }
    $found = $this->directory->search($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, 'Fictional Match');
    expect($found['customers'])->toHaveCount(20)->and($found['has_more'])->toBeTrue()
        ->and($found['customers'][0]['name'])->toBe('Fictional Match 01 Fictional');
});

it('scopes contact reads creations and create-response recovery to the authorized tenant and register', function (): void {
    $created = ($this->create)($input = posDirectoryContact());
    $other = PosFixture::merchant('other');
    $foreign = posDirectoryCustomer($other);
    expect(fn () => $this->directory->get($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $foreign->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $foreign->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->directory->search($this->fixture->tenant, $this->fixture->cashier, $other->register->id, 'Casey'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->directory->create($this->fixture->tenant, $this->fixture->cashier, $other->register->id, posDirectoryContact()))->toThrow(ModelNotFoundException::class);
    $register = $this->fixture->register->replicate();
    $register->reader_id .= '_second';
    $register->save();
    expect($this->directory->findCreation($this->fixture->tenant, $this->fixture->cashier, $register->id, $input['request_id']))->toBeNull();
    posDirectoryHttp(fn () => $this->directory->create($this->fixture->tenant, $this->fixture->cashier, $register->id, $input), 409);
    expect($this->directory->search($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, 'Casey')['customers'])
        ->toHaveCount(1)->and(WebsiteCustomer::query()->count())->toBe(2)->and($created['customer']['id'])->not->toBe($foreign->id);
});

it('keeps directory actions closed when simulation or current cashier authority is unavailable', function (string $gate): void {
    $customer = posDirectoryCustomer($this->fixture);
    match ($gate) {
        'disabled' => config()->set('pos.enabled', false),
        'unallowlisted' => config()->set('pos.tenant_ids', []),
        'production' => app()->instance('env', 'production'),
        'cashier revoked' => $this->fixture->register->update(['cashier_user_ids' => []]),
    };
    try {
        foreach ([
            fn () => $this->directory->search($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, 'Casey'),
            fn () => ($this->create)(posDirectoryContact()),
            fn () => $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customer->id),
        ] as $operation) {
            posDirectoryHttp($operation, $gate === 'cashier revoked' ? 403 : 404);
        }
        expect(WebsiteCustomer::query()->count())->toBe(1)->and(PosPaymentAttempt::query()->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
})->with(['disabled', 'unallowlisted', 'production', 'cashier revoked']);

it('reports only identified confirmed sales at the shown register with separate refunds and unchanged issued contacts', function (): void {
    $customer = posDirectoryCustomer($this->fixture);
    $this->sales->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id,
        [...$this->fixture->input(), 'tender' => 'cash', 'cash_received_cents' => 3000]);
    $pending = $this->sales->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id,
        [...$this->fixture->input(), 'website_customer_id' => $customer->id]);
    $empty = $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customer->id);
    expect($empty['paid_sale_count'])->toBe(0)->and($empty['gross_cents'])->toBe(0)->and($empty['history_complete'])->toBeTrue();
    $this->sales->simulate($this->fixture->tenant, $this->fixture->cashier, $pending->id, 'approved');
    $this->sales->refund($this->fixture->tenant, $this->fixture->manager, $pending->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional customer purchase return']);
    $second = ($this->paidFor)($customer, null, 'cash');
    $register = $this->fixture->register->replicate();
    $register->reader_id .= '_second';
    $register->save();
    ($this->paidFor)($customer, $register->id, 'cash');
    $customer->update(['first_name' => 'Changed', 'status' => 'inactive']);
    $history = $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customer->id);
    expect($history['scope'])->toBe('register')->and($history['coverage'])->toBe('latest_100_confirmed_practice_sales')
        ->and($history['paid_sale_count'])->toBe(2)->and(array_column($history['sales'], 'attempt_id'))->toBe([$second->id, $pending->id])
        ->and($history['gross_cents'])->toBe(5412)->and($history['confirmed_refunds_cents'])->toBe(500)
        ->and($history['net_cents'])->toBe(4912)->and($history['pending_refunds_cents'])->toBe(0)
        ->and($history['sales'][0]['customer_snapshot']['name'])->toBe('Casey Fictional')
        ->and($history['history_complete'])->toBeTrue()->and($history['has_more'])->toBeFalse()
        ->and(MarketingProfile::query()->count())->toBe(0)->and(MarketingConsentEvent::query()->count())->toBe(0);
});

it('keeps unresolved refunds separate from confirmed refunds and net collections', function (): void {
    $customer = posDirectoryCustomer($this->fixture);
    $attempt = ($this->paidFor)($customer);
    $this->sales->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional confirmed history refund']);
    $concrete = app(DatabaseSimulatedReader::class);
    $reader = Mockery::mock(SimulatedReader::class);
    $reader->shouldReceive('key')->andReturn('simulated_reader_v1');
    $reader->shouldReceive('capabilities')->andReturn(['environment' => 'simulation', 'physical_readers' => false, 'offline' => false, 'partial_refunds' => true]);
    $reader->shouldReceive('createRefund')->once()->andReturnUsing(function (array $snapshot, string $key) use ($concrete): array {
        $concrete->createRefund($snapshot, $key);
        throw new RuntimeException('Fictional refund response loss');
    });
    app()->instance(SimulatedReader::class, $reader);
    app(PosService::class)->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Fictional unknown history refund']);
    $before = PosSimulatorObject::query()->count();
    $history = $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customer->id);
    expect($history['gross_cents'])->toBe(2706)->and($history['confirmed_refunds_cents'])->toBe(500)
        ->and($history['pending_refunds_cents'])->toBe(1000)->and($history['net_cents'])->toBe(2206)
        ->and($history['sales'][0]['refunds'])->toHaveCount(2)->and(PosSimulatorObject::query()->count())->toBe($before);
});

it('rejects malformed saved customer or refund bindings instead of showing misleading purchase history', function (string $tamper): void {
    $customer = posDirectoryCustomer($this->fixture);
    $attempt = ($this->paidFor)($customer);
    $refund = $this->sales->refund($this->fixture->tenant, $this->fixture->manager, $attempt->id,
        ['request_id' => (string) Str::uuid(), 'amount_cents' => 500, 'reason' => 'Fictional checked history refund']);
    if ($tamper === 'customer snapshot') {
        $attempt->update(['snapshot' => [...$attempt->snapshot, 'website_customer_id' => $customer->id + 1000]]);
    } elseif ($tamper === 'refund merchant') {
        $refund->update(['snapshot' => [...$refund->snapshot, 'merchant_id' => 'sim_unrelated_merchant']]);
    } elseif ($tamper === 'refund amount') {
        $refund->update(['amount_cents' => 600]);
    } else {
        $refund->update(['amount_cents' => 3000, 'snapshot' => [...$refund->snapshot, 'amount_cents' => 3000]]);
    }
    posDirectoryHttp(fn () => $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customer->id), 409);
})->with(['customer snapshot', 'refund merchant', 'refund amount', 'over-refund totals']);

it('labels latest one hundred sale totals as incomplete when older confirmed history exists', function (): void {
    $customer = posDirectoryCustomer($this->fixture);
    $this->fixture->product->update(['track_inventory' => false]);
    $this->fixture->variant->update(['inventory_quantity' => null, 'price_cents' => 10000]);
    $older = $this->sales->start($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id,
        [...$this->fixture->input(), 'website_customer_id' => $customer->id, 'tender' => 'cash', 'cash_received_cents' => 20000]);
    $this->fixture->variant->update(['price_cents' => 2500]);
    foreach (range(1, 100) as $index) {
        ($this->paidFor)($customer, null, 'cash');
    }
    $history = $this->directory->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customer->id);
    expect($history['paid_sale_count'])->toBe(100)->and($history['sales'])->toHaveCount(100)
        ->and(array_column($history['sales'], 'attempt_id'))->not->toContain($older->id)
        ->and($history['gross_cents'])->toBe(270600)->and($history['net_cents'])->toBe(270600)
        ->and($history['confirmed_refunds_cents'])->toBe(0)->and($history['has_more'])->toBeTrue()
        ->and($history['history_complete'])->toBeFalse()->and($history['coverage'])->toBe('latest_100_confirmed_practice_sales');
});
