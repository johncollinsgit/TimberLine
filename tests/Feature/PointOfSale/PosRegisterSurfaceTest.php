<?php

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosSimulatorObject;
use App\Models\TenantPaymentAccount;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Services\PointOfSale\PosService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PosFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->fixture = PosFixture::merchant('register-surface');
    $this->sales = app(PosService::class);
    $this->url = fn (string $route, array $parameters = []): string => route($route, [...$parameters, 'tenant' => $this->fixture->tenant->slug]);
    $this->begin = fn () => $this->sales->start($this->fixture->tenant, $this->fixture->manager, $this->fixture->register->id, $this->fixture->input());
});

function exportPosRegisterSurface(string $name, \Illuminate\Testing\TestResponse $response): void
{
    if (getenv('POS_REGISTER_FIXTURES') !== '1') {
        return;
    }
    abort_unless(app()->environment('testing') && config('database.default') === 'sqlite'
        && config('database.connections.sqlite.database') === ':memory:', 500);
    $directory = '/tmp/everbranch-pos-preview';
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $html = $response->getContent();
    foreach (['brand/everbranch-mark.svg', 'images/pos/counter-illustration.webp'] as $asset) {
        $html = str_replace(asset($asset), 'file://'.public_path($asset), $html);
    }
    file_put_contents($directory.'/'.$name.'.html', $html);
}

it('shows a private owner setup hub from saved status without provider calls or setup writes', function (): void {
    $account = TenantPaymentAccount::create(['tenant_id' => $this->fixture->tenant->id, 'provider_account_id' => 'acct_setup_private_fixture',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'last_checked_at' => '2026-10-20 10:15:00',
        'metadata' => ['routing_mode' => 'direct', 'private_fixture' => 'Do not expose provider metadata']]);
    config()->set(['tenant_payments.setup_enabled' => true, 'tenant_payments.tenant_slugs' => [$this->fixture->tenant->slug]]);
    $before = $account->fresh()->getRawOriginal();
    $response = $this->actingAs($this->fixture->manager)->get(($this->url)('pos.setup'))->assertOk()
        ->assertSeeText('Prepare your checkout.')->assertSeeText('Your Stripe setup')->assertSeeText('Connection saved')
        ->assertSeeText('Oct 20, 2026 10:15 UTC')->assertSeeText('Open secure payment setup')->assertSeeText('Your Stripe password belongs in Stripe')
        ->assertSeeText('The physical Terminal integration is unavailable in this build.')->assertSeeText('A saved Stripe connection does not verify live POS.')
        ->assertSeeText('AI-generated illustration — reader compatibility is pending review.')
        ->assertSeeText('Postage, carrier rates, shipping-label purchase and service workflows need separate acceptance.')
        ->assertDontSee('acct_setup_private_fixture')->assertDontSee('Do not expose provider metadata')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($account->fresh()->getRawOriginal())->toEqual($before)->and(TenantPaymentAccount::count())->toBe(1)
        ->and(PosPaymentAttempt::count())->toBe(0)->and(PosSimulatorObject::count())->toBe(0)->and(WebsiteOrder::count())->toBe(0)
        ->and(config('pos.terminal_enabled'))->toBeFalse()->and(config('pos.live_payments_enabled'))->toBeFalse();
    Http::assertNothingSent();
    exportPosRegisterSurface('setup-owner', $response);
});

it('gives staff sign-in help without merchant connection or private payment details', function (): void {
    TenantPaymentAccount::create(['tenant_id' => $this->fixture->tenant->id, 'provider_account_id' => 'acct_staff_hidden_fixture',
        'status' => 'restricted', 'charges_enabled' => false, 'payouts_enabled' => false, 'last_checked_at' => '2026-10-20 10:15:00',
        'platform_fee_bps' => 123, 'metadata' => ['private_fixture' => 'Hidden merchant billing evidence']]);
    config()->set(['tenant_payments.setup_enabled' => true, 'tenant_payments.tenant_slugs' => [$this->fixture->tenant->slug]]);
    $response = $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.setup'))->assertOk()
        ->assertSeeText('Your Everbranch sign-in')->assertSeeText('Signed in to Everbranch')->assertSeeText($this->fixture->cashier->name)
        ->assertSeeText('Your practice registers')->assertSeeText($this->fixture->register->name)
        ->assertDontSeeText('Your Stripe setup')->assertDontSeeText('Open secure payment setup')->assertDontSeeText('Needs attention')
        ->assertDontSeeText('Payouts')->assertDontSeeText('Online-payment checks')->assertDontSee('acct_staff_hidden_fixture')
        ->assertDontSee('Hidden merchant billing evidence')->assertDontSee('Oct 20, 2026 10:15 UTC');
    Http::assertNothingSent();
    exportPosRegisterSurface('setup-staff', $response);
});

it('keeps provider setup links gated and shows an honest register assignment blank state', function (): void {
    config()->set('tenant_payments.setup_enabled', false);
    $this->actingAs($this->fixture->manager)->get(($this->url)('pos.setup'))->assertOk()
        ->assertSeeText('Payment setup is not enabled for this workspace.')->assertDontSeeText('Open secure payment setup');
    $this->fixture->register->update(['cashier_user_ids' => []]);
    $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.setup'))->assertOk()
        ->assertSeeText('No practice register is available to you yet.')->assertDontSeeText($this->fixture->register->name);
    Http::assertNothingSent();
});

it('applies cashier assignment before the register list bound', function (): void {
    $this->fixture->register->update(['cashier_user_ids' => []]);
    for ($index = 0; $index < 21; $index++) {
        $register = $this->fixture->register->replicate();
        $register->name = $index === 20 ? 'Assigned register beyond other registers' : 'Unassigned register '.$index;
        $register->reader_id = 'sim_reader_list_'.$index;
        $register->cashier_user_ids = $index === 20 ? [$this->fixture->cashier->id] : [];
        $register->save();
    }
    $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.setup'))->assertOk()
        ->assertSeeText('Assigned register beyond other registers')->assertDontSeeText('Unassigned register 0');
    Http::assertNothingSent();
});

it('denies the setup hub outside its allowed environment and for inactive membership', function (): void {
    $this->fixture->cashier->tenants()->updateExistingPivot($this->fixture->tenant->id, ['membership_active' => false]);
    $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.setup'))->assertForbidden();
    app()->instance('env', 'production');
    $this->actingAs($this->fixture->manager)->get(($this->url)('pos.setup'))->assertNotFound();
    Http::assertNothingSent();
});

it('renders an authorized private register with no provider or sale writes on GET', function (): void {
    $response = $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.register', ['register' => $this->fixture->register->id]))
        ->assertOk()->assertSeeText('Simulation — no money moves')->assertSeeText('Fictional retail kit')
        ->assertSeeText('Review simulated sale')->assertSeeText('Recent sales and recovery')
        ->assertDontSee('sim_merchant_register-surface')->assertDontSee('sim_reader_register-surface')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect(PosPaymentAttempt::count())->toBe(0)->and(PosSimulatorObject::count())->toBe(0)->and(WebsiteOrder::count())->toBe(0);
    Http::assertNothingSent();
    exportPosRegisterSurface('register', $response);
});

it('prepares a server-priced sale then exposes a receipt only after verified simulation', function (): void {
    $input = $this->fixture->input();
    $input['total_cents'] = 1;
    $input['lines'][] = ['variant_id' => $this->fixture->variant->id + 999, 'quantity' => 0];
    $this->actingAs($this->fixture->manager)->post(($this->url)('pos.start', ['register' => $this->fixture->register->id]), $input)->assertRedirect();
    $attempt = PosPaymentAttempt::sole();
    $response = $this->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertOk()
        ->assertSeeText('Waiting for simulated reader')->assertSeeText('$27.06')->assertSeeText('Approve simulation')
        ->assertDontSeeText('Simulated sale verified')->assertDontSee('sim_merchant_register-surface');
    exportPosRegisterSurface('pending', $response);
    $this->get(($this->url)('pos.receipt', ['attempt' => $attempt->id]))->assertConflict();
    $this->post(($this->url)('pos.simulate', ['attempt' => $attempt->id]), ['outcome' => 'approved'])->assertRedirect();
    $this->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertOk()->assertSeeText('Simulated sale verified')->assertSeeText('View simulation receipt');
    $response = $this->get(($this->url)('pos.receipt', ['attempt' => $attempt->id]))->assertOk()
        ->assertSeeText('Simulation receipt')->assertSeeText('Practice transaction · No money moved')->assertSeeText('$27.06')
        ->assertDontSee($attempt->fresh()->provider_id)->assertDontSee($attempt->snapshot['nonce']);
    exportPosRegisterSurface('receipt', $response);
    expect(PosPaymentAttempt::count())->toBe(1)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4);
    Http::assertNothingSent();
});

it('rejects unsupported foreign customer identifiers before any guest sale or customer-history write', function (): void {
    $other = PosFixture::merchant('foreign-native-customer');
    $customer = WebsiteCustomer::create(['tenant_id' => $other->tenant->id, 'first_name' => 'Fictional',
        'last_name' => 'Foreign shopper', 'email' => 'foreign-pos-customer@example.test', 'status' => 'active']);
    $before = $customer->fresh()->getRawOriginal();
    $input = [...$this->fixture->input(), 'customer_id' => $customer->id, 'website_customer_id' => $customer->id];
    $this->actingAs($this->fixture->manager)->postJson(($this->url)('pos.start', ['register' => $this->fixture->register->id]), $input)
        ->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'website_customer_id']);
    expect(WebsiteOrder::count())->toBe(0)->and(PosPaymentAttempt::count())->toBe(0)
        ->and(WebsiteInventoryReservation::count())->toBe(0)->and(WebsiteInventoryMovement::count())->toBe(0)
        ->and(PosSimulatorObject::count())->toBe(0)->and(WebsiteOrderEvent::count())->toBe(0)
        ->and(WebsiteCustomer::count())->toBe(1)->and($customer->orders()->count())->toBe(0)
        ->and($customer->fresh()->getRawOriginal())->toEqual($before)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
    Http::assertNothingSent();
});

it('keeps unknown results visible and blocks another checkout until recovery', function (): void {
    $attempt = ($this->begin)();
    $this->actingAs($this->fixture->cashier)->post(($this->url)('pos.simulate', ['attempt' => $attempt->id]), ['outcome' => 'unknown'])->assertRedirect();
    $this->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertOk()->assertSeeText('Outcome needs confirmation')
        ->assertSeeText('Do not create a replacement sale')->assertSeeText('Check existing payment')->assertDontSeeText('View simulation receipt');
    $this->get(($this->url)('pos.register', ['register' => $this->fixture->register->id]))->assertOk()->assertSeeText('Finish the open sale before starting another.');
    $this->post(($this->url)('pos.start', ['register' => $this->fixture->register->id]), $this->fixture->input())->assertConflict();
    expect(PosPaymentAttempt::count())->toBe(1);
});

it('looks up a saved checkout request without recreating or reconciling it', function (): void {
    $attempt = ($this->begin)();
    $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.request-status', ['register' => $this->fixture->register->id, 'requestId' => $attempt->request_id]))
        ->assertOk()->assertJsonPath('record.id', $attempt->id)->assertJsonPath('record.request_id', $attempt->request_id)
        ->assertJsonPath('record.status', 'awaiting_reader')->assertJsonMissingPath('record.snapshot')
        ->assertJsonMissingPath('record.provider_id')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($attempt->fresh()->status)->toBe('awaiting_reader')->and(PosPaymentAttempt::count())->toBe(1)->and(PosSimulatorObject::count())->toBe(1);
    $this->get(($this->url)('pos.request-status', ['register' => $this->fixture->register->id, 'requestId' => (string) Str::uuid()]))
        ->assertOk()->assertExactJson(['record' => null]);
    Http::assertNothingSent();
});

it('rejects foreign register sale receipt and refund identifiers through the real tenant middleware', function (): void {
    $other = PosFixture::merchant('foreign-surface');
    $attempt = $this->sales->start($other->tenant, $other->manager, $other->register->id, $other->input());
    $this->sales->simulate($other->tenant, $other->manager, $attempt->id, 'approved');
    $refund = $this->sales->refund($other->tenant, $other->manager, $attempt->id, ['request_id' => (string) Str::uuid(), 'amount_cents' => 100, 'reason' => 'Fictional foreign refund']);
    $this->fixture->manager->tenants()->attach($other->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->actingAs($this->fixture->manager);
    $this->get(($this->url)('pos.register', ['register' => $other->register->id]))->assertNotFound();
    $this->get(($this->url)('pos.request-status', ['register' => $other->register->id, 'requestId' => $attempt->request_id]))->assertNotFound();
    $this->get(($this->url)('pos.request-status', ['register' => $this->fixture->register->id, 'requestId' => $attempt->request_id]))->assertOk()->assertExactJson(['record' => null]);
    $this->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertNotFound();
    $this->get(($this->url)('pos.receipt', ['attempt' => $attempt->id]))->assertNotFound();
    $this->post(($this->url)('pos.reconcile', ['attempt' => $attempt->id]))->assertNotFound();
    $this->post(($this->url)('pos.refund.reconcile', ['refund' => $refund->id]))->assertNotFound();
    expect(PosRefund::count())->toBe(1);
});

it('offers manager refunds and denies the same action to an authorized cashier', function (): void {
    $attempt = ($this->begin)();
    $this->sales->simulate($this->fixture->tenant, $this->fixture->manager, $attempt->id, 'approved');
    $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertOk()
        ->assertSeeText('A manager must authorize refunds.')->assertDontSeeText('Request simulated refund');
    $input = ['request_id' => (string) Str::uuid(), 'amount' => '5.00', 'reason' => 'Fictional manager refund reason'];
    $this->post(($this->url)('pos.refund', ['attempt' => $attempt->id]), $input)->assertForbidden();
    expect(PosRefund::count())->toBe(0);
    $this->actingAs($this->fixture->manager)->post(($this->url)('pos.refund', ['attempt' => $attempt->id]), $input)->assertRedirect();
    expect(PosRefund::sole()->amount_cents)->toBe(500);
    $this->get(($this->url)('pos.receipt', ['attempt' => $attempt->id]))->assertOk()->assertSeeText('Verified simulated refunds')->assertSeeText('$5.00');
    $this->post(($this->url)('pos.refund', ['attempt' => $attempt->id]), ['request_id' => (string) Str::uuid(), 'amount' => '22.06', 'reason' => 'Fictional remaining full refund'])->assertRedirect();
    $this->get(($this->url)('pos.receipt', ['attempt' => $attempt->id]))->assertOk()->assertSeeText('Verified simulated refunds')->assertSeeText('$27.06');
    $this->actingAs($this->fixture->cashier)->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertOk()
        ->assertDontSeeText($input['reason'])->assertDontSee($input['request_id']);
    Http::assertNothingSent();
});

it('rejects altered financial terms on read without changing the saved attempt or provider', function (string $change): void {
    $attempt = ($this->begin)();
    $this->sales->simulate($this->fixture->tenant, $this->fixture->manager, $attempt->id, 'approved');
    $order = WebsiteOrder::findOrFail($attempt->website_order_id);
    if ($change === 'total') {
        $order->update(['total_cents' => $order->total_cents + 1]);
    } else {
        $order->lines()->firstOrFail()->update(['title' => 'Changed after sale']);
    }
    $this->actingAs($this->fixture->manager)->get(($this->url)('pos.show', ['attempt' => $attempt->id]))->assertConflict();
    $this->get(($this->url)('pos.receipt', ['attempt' => $attempt->id]))->assertConflict();
    expect($attempt->fresh()->status)->toBe('succeeded')->and(PosSimulatorObject::count())->toBe(1);
    Http::assertNothingSent();
})->with(['total', 'line']);

it('requires sign-in and denies production even when all simulation flags are set', function (): void {
    $url = ($this->url)('pos.register', ['register' => $this->fixture->register->id]);
    $this->get($url)->assertRedirect(route('login'));
    app()->instance('env', 'production');
    $this->actingAs($this->fixture->manager)->get($url)->assertNotFound();
    expect(PosPaymentAttempt::count())->toBe(0)->and(PosSimulatorObject::count())->toBe(0);
    Http::assertNothingSent();
});
