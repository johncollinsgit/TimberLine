<?php

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosSimulatorObject;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Services\PointOfSale\PosCustomerDirectory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PosFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->fixture = PosFixture::merchant('identified-demo');
    $this->url = fn (string $route, array $parameters = []): string => route($route, [...$parameters, 'tenant' => $this->fixture->tenant->slug]);
    $this->createCustomer = fn (array $overrides = []) => $this->postJson(($this->url)('pos.customers.create', ['register' => $this->fixture->register->id]), [
        'request_id' => (string) Str::uuid(), 'first_name' => 'Fictional', 'last_name' => 'Practice Shopper', 'email' => 'practice-shopper@example.test', ...$overrides,
    ]);
});

it('completes an identified cashier HTTP transaction once with a private frozen receipt and guest isolation', function (): void {
    $this->actingAs($this->fixture->cashier);
    $createRequest = (string) Str::uuid();
    $creation = ($this->createCustomer)(['request_id' => $createRequest])->assertCreated()->assertJsonPath('simulation', true);
    $customerId = $creation->json('customer.id');
    $this->getJson(($this->url)('pos.customers.search', ['register' => $this->fixture->register->id, 'query' => 'practice-shopper@example.test']))
        ->assertOk()->assertJsonPath('customers.0.id', $customerId)->assertJsonMissingPath('customers.0.notes');
    $this->getJson(($this->url)('pos.customers.request', ['register' => $this->fixture->register->id, 'requestId' => $createRequest]))
        ->assertOk()->assertJsonPath('customer.id', $customerId)->assertJsonMissingPath('customer.notes');
    ($this->createCustomer)(['request_id' => $createRequest])->assertCreated()->assertJsonPath('customer.id', $customerId);
    expect(WebsiteCustomer::count())->toBe(1);
    $historyUrl = ($this->url)('pos.customers.history', ['register' => $this->fixture->register->id, 'customer' => $customerId]);
    $this->get($historyUrl)->assertOk()->assertSeeText('No confirmed practice sales yet.');

    $saleRequest = (string) Str::uuid();
    $input = [...$this->fixture->input(1, $saleRequest), 'website_customer_id' => $customerId, 'tender' => 'card'];
    $this->post(($this->url)('pos.start', ['register' => $this->fixture->register->id]), $input)->assertRedirect();
    $attempt = PosPaymentAttempt::sole();
    $order = WebsiteOrder::findOrFail($attempt->website_order_id);
    expect($attempt->status)->toBe('awaiting_reader')->and($order->website_customer_id)->toBe($customerId)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
    $this->get($historyUrl)->assertOk()->assertSeeText('No confirmed practice sales yet.')->assertDontSeeText($order->number);
    $this->post(($this->url)('pos.simulate', ['attempt' => $attempt->id]), ['outcome' => 'approved'])->assertRedirect();
    $this->post(($this->url)('pos.reconcile', ['attempt' => $attempt->id]))->assertRedirect();
    $this->post(($this->url)('pos.start', ['register' => $this->fixture->register->id]), $input)->assertRedirect();
    expect(PosPaymentAttempt::count())->toBe(1)->and(WebsiteInventoryMovement::count())->toBe(1)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(4)->and($attempt->fresh()->status)->toBe('succeeded');
    $receiptUrl = ($this->url)('pos.receipt', ['attempt' => $attempt->id]);
    $receipt = $this->get($receiptUrl)->assertOk()->assertSeeText('Fictional Practice Shopper')->assertSeeText('$27.06')
        ->assertSeeText('No money moved')->assertDontSeeText('practice-shopper@example.test');
    WebsiteCustomer::findOrFail($customerId)->update(['first_name' => 'Changed profile']);
    $this->get($receiptUrl)->assertOk()->assertSeeText('Fictional Practice Shopper')->assertDontSeeText('Changed profile');
    $history = $this->get($historyUrl)->assertOk()->assertSeeText('Changed profile')->assertSeeText($order->number)
        ->assertSeeText('Fictional Practice Shopper')->assertSeeText('A manager must authorize refunds from the original sale.')->assertSeeText('$27.06');

    $this->post(($this->url)('pos.start', ['register' => $this->fixture->register->id]), $this->fixture->input())->assertRedirect();
    $guest = PosPaymentAttempt::latest('id')->firstOrFail();
    $this->post(($this->url)('pos.simulate', ['attempt' => $guest->id]), ['outcome' => 'approved'])->assertRedirect();
    $guestOrder = WebsiteOrder::findOrFail($guest->website_order_id);
    $this->get($historyUrl)->assertOk()->assertSeeText($order->number)->assertDontSeeText($guestOrder->number);
    $totals = app(PosCustomerDirectory::class)->history($this->fixture->tenant, $this->fixture->cashier, $this->fixture->register->id, $customerId);
    expect($totals['paid_sale_count'])->toBe(1)->and($totals['gross_cents'])->toBe(2706)->and($totals['net_cents'])->toBe(2706)
        ->and($guestOrder->website_customer_id)->toBeNull()->and(WebsiteInventoryMovement::count())->toBe(2)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3);
    $refundRequest = (string) Str::uuid();
    $this->actingAs($this->fixture->manager)->post(($this->url)('pos.refund', ['attempt' => $attempt->id]), [
        'request_id' => $refundRequest, 'amount' => '5.00', 'reason' => 'Fictional practice return for the walkthrough',
    ])->assertRedirect();
    $refund = PosRefund::sole();
    $this->post(($this->url)('pos.refund.reconcile', ['refund' => $refund->id]))->assertRedirect();
    expect($refund->fresh()->status)->toBe('succeeded')->and(PosRefund::count())->toBe(1)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(3);
    $totals = app(PosCustomerDirectory::class)->history($this->fixture->tenant, $this->fixture->manager, $this->fixture->register->id, $customerId);
    expect($totals['gross_cents'])->toBe(2706)->and($totals['confirmed_refunds_cents'])->toBe(500)->and($totals['net_cents'])->toBe(2206);
    $history = $this->get($historyUrl)->assertOk()->assertSeeText('Review sale or manager refund')->assertSeeText('$5.00')->assertSeeText('$22.06');
    $receipt = $this->get($receiptUrl)->assertOk()->assertSeeText('Fictional Practice Shopper')->assertSeeText('Verified simulated refunds')->assertSeeText('$5.00');
    $cashRequest = (string) Str::uuid();
    $this->actingAs($this->fixture->cashier)->post(($this->url)('pos.start', ['register' => $this->fixture->register->id]), [
        ...$this->fixture->input(1, $cashRequest), 'tender' => 'cash', 'cash_received_cents' => 3000,
    ])->assertRedirect();
    $cash = PosPaymentAttempt::latest('id')->firstOrFail();
    expect($cash->status)->toBe('succeeded')->and($cash->snapshot['change_cents'])->toBe(294)
        ->and(WebsiteInventoryMovement::count())->toBe(3)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(2);
    $cashReceipt = $this->get(($this->url)('pos.receipt', ['attempt' => $cash->id]))->assertOk()->assertSeeText('Guest')->assertSeeText('$30.00')->assertSeeText('$2.94');
    $this->get($historyUrl)->assertOk()->assertDontSeeText(WebsiteOrder::findOrFail($cash->website_order_id)->number);
    Http::assertNothingSent();

    if (getenv('POS_REGISTER_FIXTURES') === '1') {
        abort_unless(app()->environment('testing') && config('database.default') === 'sqlite'
            && config('database.connections.sqlite.database') === ':memory:', 500);
        $directory = '/tmp/everbranch-pos-preview';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $report = ['environment' => 'testing; SQLite in-memory', 'payment_provider' => 'Pure local DatabaseSimulatedReader; no Stripe API or sandbox acceptance',
            'fictional_data_only' => true, 'tenant_id' => $this->fixture->tenant->id, 'register_id' => $this->fixture->register->id,
            'cashier_id' => $this->fixture->cashier->id, 'customer_id' => $customerId, 'customer_create_request_id' => $createRequest,
            'attempt_id' => $attempt->id, 'order_id' => $order->id, 'order_number' => $order->number, 'sale_request_id' => $saleRequest,
            'status' => $attempt->fresh()->status, 'tender' => 'card', 'subtotal_cents' => 2500, 'tax_cents' => 206, 'total_cents' => 2706,
            'identified_sale_stock_before' => 5, 'identified_sale_stock_after_retries' => 4, 'identified_sale_inventory_movements' => 1,
            'guest_attempt_id' => $guest->id, 'guest_order_id' => $guestOrder->id, 'guest_excluded_from_history' => true,
            'shown_history_gross_cents' => $totals['gross_cents'], 'shown_history_refunds_cents' => $totals['confirmed_refunds_cents'],
            'shown_history_net_cents' => $totals['net_cents'], 'refund_id' => $refund->id, 'refund_request_id' => $refundRequest,
            'refund_status_after_reconcile' => $refund->fresh()->status, 'refunds_restock_inventory' => false,
            'cash_attempt_id' => $cash->id, 'cash_request_id' => $cashRequest, 'cash_status' => $cash->status, 'cash_total_cents' => 2706,
            'cash_received_cents' => $cash->snapshot['cash_received_cents'], 'cash_change_cents' => $cash->snapshot['change_cents'],
            'cash_guest_excluded_from_history' => true, 'final_stock_after_three_sales_and_refund' => 2, 'receipt_url' => $receiptUrl, 'history_url' => $historyUrl,
            'external_http_requests' => 0, 'physical_reader_verified' => false, 'physical_printer_verified' => false];
        file_put_contents($directory.'/identified-transaction.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        foreach (['identified-receipt' => $receipt, 'identified-history' => $history, 'walkthrough-cash-receipt' => $cashReceipt] as $name => $response) {
            $html = str_replace(asset('brand/everbranch-mark.svg'), 'file://'.public_path('brand/everbranch-mark.svg'), $response->getContent());
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
        file_put_contents($directory.'/identified-transaction.html', '<!doctype html><html lang="en"><meta charset="utf-8"><title>Fictional POS transaction evidence</title><h1>Zero-real-money transaction evidence</h1><p>Pure local provider simulation, not Stripe sandbox or hardware acceptance.</p><pre>'.htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8').'</pre></html>');
    }
});

it('keeps customer lookup and history private read-only scoped and denied in production', function (): void {
    $other = PosFixture::merchant('foreign-customer-directory');
    $own = WebsiteCustomer::create(['tenant_id' => $this->fixture->tenant->id, 'first_name' => 'Fictional own', 'status' => 'active']);
    $foreign = WebsiteCustomer::create(['tenant_id' => $other->tenant->id, 'first_name' => 'Fictional foreign', 'email' => 'secret-foreign@example.test', 'status' => 'active']);
    $this->actingAs($this->fixture->cashier);
    $this->getJson(($this->url)('pos.customers.search', ['register' => $this->fixture->register->id, 'query' => 'Fictional']))
        ->assertOk()->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.id', $own->id)->assertDontSee('secret-foreign@example.test')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get(($this->url)('pos.customers.history', ['register' => $this->fixture->register->id, 'customer' => $foreign->id]))->assertNotFound();
    $this->getJson(($this->url)('pos.customers.search', ['register' => $other->register->id, 'query' => 'Fictional']))->assertNotFound();
    $this->postJson(($this->url)('pos.start', ['register' => $this->fixture->register->id]), [...$this->fixture->input(), 'website_customer_id' => $foreign->id])->assertNotFound();
    expect(WebsiteCustomer::count())->toBe(2)->and(PosPaymentAttempt::count())->toBe(0)->and(WebsiteOrder::count())->toBe(0)
        ->and(WebsiteInventoryReservation::count())->toBe(0)->and(PosSimulatorObject::count())->toBe(0);
    app()->instance('env', 'production');
    $this->getJson(($this->url)('pos.customers.search', ['register' => $this->fixture->register->id, 'query' => 'Fictional']))->assertNotFound();
    $this->get(($this->url)('pos.customers.history', ['register' => $this->fixture->register->id, 'customer' => $own->id]))->assertNotFound();
    ($this->createCustomer)()->assertNotFound();
    Http::assertNothingSent();
});

it('rejects duplicate native emails without selecting merging or creating a second profile', function (): void {
    $this->actingAs($this->fixture->cashier);
    ($this->createCustomer)()->assertCreated();
    ($this->createCustomer)(['first_name' => 'Different person', 'email' => 'PRACTICE-SHOPPER@example.test'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    expect(WebsiteCustomer::count())->toBe(1)->and(PosPaymentAttempt::count())->toBe(0)->and(WebsiteOrder::count())->toBe(0);
    Http::assertNothingSent();
});
