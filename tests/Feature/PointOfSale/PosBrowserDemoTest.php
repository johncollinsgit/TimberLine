<?php

use App\Models\TenantPaymentAccount;
use App\Models\WebsiteCustomer;
use App\Services\PointOfSale\PosAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosFixture;

function posBrowserDemoBodyWithoutNonce(string $html): string
{
    return preg_replace('/nonce=(["\'])[^"\']+\1/', 'nonce="[request-nonce]"', $html);
}

function posBrowserDemoCanonicalCounts(): array
{
    $counts = [];
    foreach (['users', 'tenants', 'tenant_user', 'website_customers', 'website_products', 'website_product_variants',
        'website_orders', 'website_order_lines', 'website_payments', 'website_inventory_reservations', 'website_inventory_movements',
        'pos_registers', 'pos_payment_attempts', 'pos_refunds', 'pos_simulator_objects', 'tenant_payment_accounts', 'sessions'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['app.debug' => false, 'app.url' => 'https://app.theeverbranch.com',
        'tenancy.domains.canonical.base_domain' => 'theeverbranch.com', 'tenancy.domains.canonical.public_host' => 'theeverbranch.com',
        'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.domains.legacy.base_domains' => [], 'tenancy.domains.legacy.public_hosts' => [], 'tenancy.domains.legacy.landlord_hosts' => [],
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com', 'theeverbranch.com'], 'tenancy.auth.host_map' => [],
        'session.driver' => 'database', 'cache.default' => 'array', 'scheduler_heartbeat.enabled' => true]);
    app('session')->forgetDrivers();
    $this->demoPath = '/platform/demos/alpha-printing-pos';
    $this->demoUrl = 'https://app.theeverbranch.com'.$this->demoPath;
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('serves an anonymous fixture-only register with a fresh restrictive CSP and no application session or scheduler writes', function (): void {
    expect(route('platform.alpha-printing-pos-demo', [], false))->toBe($this->demoPath);
    Cache::forever('scheduler:last_heartbeat', now()->subHour()->toIso8601String());
    Cache::forget('scheduler:heartbeat_check_lock');
    $before = posBrowserDemoCanonicalCounts();
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $first = $this->get($this->demoUrl)->assertOk()->assertSeeText('Alpha Printing');
        $second = $this->get($this->demoUrl)->assertOk();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(strtolower($first->getContent()))->toContain('fictional')->toContain('browser demo');
    $first->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->headers->get('X-Robots-Tag'))->toContain('noindex')->toContain('nofollow')->toContain('noarchive')
        ->and($first->headers->getCookies())->toBe([])->and($second->headers->getCookies())->toBe([])
        ->and(posBrowserDemoCanonicalCounts())->toBe($before)
        ->and(Cache::has('scheduler:heartbeat_check_lock'))->toBeFalse();
    $writes = array_filter($queries, fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query['query']) === 1);
    expect($writes)->toBe([]);
    $csp = (string) $first->headers->get('Content-Security-Policy');
    foreach (["default-src 'none'", "connect-src 'none'", "form-action 'none'", "frame-ancestors 'none'", "img-src 'self' data:"] as $directive) {
        expect($csp)->toContain($directive);
    }
    expect(preg_match("/script-src 'nonce-([^']+)'/", $csp, $nonce))->toBe(1)
        ->and(strlen($nonce[1]))->toBeGreaterThanOrEqual(22)
        ->and($csp)->toContain("style-src 'nonce-".$nonce[1]."'")->not->toContain('unsafe-inline')->not->toContain('unsafe-eval')
        ->and($second->headers->get('Content-Security-Policy'))->not->toContain($nonce[1]);
    preg_match_all('/<(script|style)\b([^>]*)>/i', $first->getContent(), $elements, PREG_SET_ORDER);
    expect($elements)->not->toBeEmpty();
    foreach ($elements as $element) {
        expect($element[2])->toContain('nonce="'.$nonce[1].'"')->not->toMatch('/\bsrc\s*=/i');
    }
    expect(posBrowserDemoBodyWithoutNonce($second->getContent()))->toBe(posBrowserDemoBodyWithoutNonce($first->getContent()));
});

it('renders the identical fictional page for a signed-in merchant without exposing or changing canonical records', function (): void {
    $fixture = PosFixture::merchant('private-browser-demo');
    $fixture->tenant->update(['name' => 'PRIVATE-TENANT-BROWSER-MARKER']);
    $fixture->product->update(['title' => 'PRIVATE-PRODUCT-BROWSER-MARKER']);
    $customer = WebsiteCustomer::query()->create(['tenant_id' => $fixture->tenant->id, 'first_name' => 'PRIVATE-CUSTOMER-BROWSER-MARKER',
        'email' => 'private-customer-browser-marker@example.test', 'status' => 'active']);
    $order = $fixture->websiteOrder();
    $order->update(['number' => 'PRIVATE-ORDER-BROWSER-MARKER', 'website_customer_id' => $customer->id]);
    TenantPaymentAccount::query()->create(['tenant_id' => $fixture->tenant->id, 'provider_account_id' => 'acct_PRIVATE_BROWSER_MARKER',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true]);
    config()->set('services.stripe.secret', 'sk_test_FICTIONAL_PRIVATE_BROWSER_MARKER');
    $sessionId = Str::random(40);
    DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $fixture->manager->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Fictional existing browser session', 'payload' => base64_encode(serialize(['private' => 'PRIVATE-SESSION-BROWSER-MARKER'])),
        'last_activity' => now()->subMinutes(5)->timestamp]);
    $before = posBrowserDemoCanonicalCounts();
    $sessionBefore = DB::table('sessions')->where('id', $sessionId)->first();
    $stockBefore = $fixture->variant->fresh()->inventory_quantity;
    $anonymous = $this->get($this->demoUrl)->assertOk();
    $authenticated = $this->actingAs($fixture->manager)->withCookie(config('session.cookie'), $sessionId)
        ->get($this->demoUrl)->assertOk();
    expect(posBrowserDemoBodyWithoutNonce($authenticated->getContent()))->toBe(posBrowserDemoBodyWithoutNonce($anonymous->getContent()))
        ->and($authenticated->headers->getCookies())->toBe([])->and(auth()->id())->toBe($fixture->manager->id)
        ->and(posBrowserDemoCanonicalCounts())->toBe($before)
        ->and(DB::table('sessions')->where('id', $sessionId)->first())->toEqual($sessionBefore)
        ->and($fixture->variant->fresh()->inventory_quantity)->toBe($stockBefore)
        ->and($order->fresh()->number)->toBe('PRIVATE-ORDER-BROWSER-MARKER');
    foreach (['PRIVATE-TENANT-BROWSER-MARKER', 'PRIVATE-PRODUCT-BROWSER-MARKER', 'PRIVATE-CUSTOMER-BROWSER-MARKER',
        'private-customer-browser-marker@example.test', 'PRIVATE-ORDER-BROWSER-MARKER', 'acct_PRIVATE_BROWSER_MARKER',
        'sk_test_FICTIONAL_PRIVATE_BROWSER_MARKER', 'PRIVATE-SESSION-BROWSER-MARKER'] as $marker) {
        $authenticated->assertDontSee($marker, false);
        $anonymous->assertDontSee($marker, false);
    }
});

it('clears prior Livewire request assets without disabling later normal asset injection', function (): void {
    app('livewire')->forceAssetInjection();
    $page = $this->get($this->demoUrl)->assertOk();
    expect($page->getContent())->not->toContain('Livewire Styles')->not->toContain('data-update-uri=');
    preg_match_all('/<(script|style)\b([^>]*)>/i', $page->getContent(), $elements, PREG_SET_ORDER);
    expect($elements)->toHaveCount(4);
    foreach ($elements as $element) {
        expect($element[2])->toMatch('/\bnonce="[^"]+"/')->not->toMatch('/\bsrc\s*=/i');
    }

    // Only this standalone request is cleared; ordinary pages keep the same
    // framework asset policy and can request their own scripts/styles.
    \Illuminate\Support\Facades\Route::get('/fixture-browser-demo-livewire-next', fn () => response('<html><head></head><body>Fixture</body></html>'));
    app('livewire')->forceAssetInjection();
    $this->get('https://app.theeverbranch.com/fixture-browser-demo-livewire-next')->assertOk()
        ->assertSee('Livewire Styles', false)->assertSee('data-update-uri=', false);
    app('livewire')->flushState();
});

it('never accepts query parameters as tenant identity fixture data routing or credentials', function (string $query): void {
    $before = posBrowserDemoCanonicalCounts();
    $this->get($this->demoUrl.'?'.$query)->assertNotFound()->assertDontSee('PRIVATE-QUERY-BROWSER-MARKER', false);
    expect(posBrowserDemoCanonicalCounts())->toBe($before);
})->with(['tenant=PRIVATE-QUERY-BROWSER-MARKER', 'tenant_id=16', 'register_id=1', 'customer_id=1',
    'order_id=1', 'token=PRIVATE-QUERY-BROWSER-MARKER', 'secret=PRIVATE-QUERY-BROWSER-MARKER',
    'fixture%5Bcustomer%5D=PRIVATE-QUERY-BROWSER-MARKER', 'unused=', 'nonce=PRIVATE-QUERY-BROWSER-MARKER']);

it('rejects a nonempty raw query string even when the query parser produces no parameters', function (): void {
    $before = posBrowserDemoCanonicalCounts();
    $this->call('GET', $this->demoUrl.'?&&', [], [], [], ['QUERY_STRING' => '&&'])->assertNotFound();
    expect(posBrowserDemoCanonicalCounts())->toBe($before);
});

it('limits fixture access to the canonical landlord host and explicit local-development hosts', function (string $host, int $status): void {
    $this->get('https://'.$host.$this->demoPath)->assertStatus($status);
})->with(['canonical landlord' => ['app.theeverbranch.com', 200], 'local browser' => ['localhost', 200], 'loopback' => ['127.0.0.1', 200],
    'public website' => ['theeverbranch.com', 404], 'untrusted host' => ['untrusted.example.test', 404],
    'local suffix is insufficient' => ['other.localhost', 404], 'canonical suffix is insufficient' => ['app.theeverbranch.com.evil.test', 404]]);

it('rejects a resolved merchant host instead of turning the public demo into a tenant register', function (): void {
    $fixture = PosFixture::merchant('host-browser-demo');
    $before = posBrowserDemoCanonicalCounts();
    $this->get('https://'.$fixture->site->subdomain.'.theeverbranch.com'.$this->demoPath)->assertNotFound();
    expect(posBrowserDemoCanonicalCounts())->toBe($before);
});

it('uses the configured canonical landlord host rather than an unchanging host alias', function (): void {
    config()->set('tenancy.domains.canonical.landlord_host', 'demo-entry.theeverbranch.com');
    $this->get('https://demo-entry.theeverbranch.com'.$this->demoPath)->assertOk()->assertSeeText('Alpha Printing');
    $this->get($this->demoUrl)->assertNotFound();
});

it('supports HEAD and denies HTTP mutation methods', function (string $method): void {
    $before = posBrowserDemoCanonicalCounts();
    if ($method === 'HEAD') {
        $this->head($this->demoUrl)->assertOk()->assertContent('');
    } else {
        $this->call($method, $this->demoUrl, ['tenant_id' => 16, 'amount_cents' => 1])->assertStatus(405);
        // Unsupported methods use the application's ordinary error fallback,
        // which may maintain its own session but cannot mutate business records.
        unset($before['sessions']);
    }
    $after = posBrowserDemoCanonicalCounts();
    if ($method !== 'HEAD') {
        unset($after['sessions']);
    }
    expect($after)->toBe($before);
})->with(['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']);

it('offers no tenant data or action endpoint beneath the fictional demo path', function (): void {
    foreach (['/api', '/customers', '/catalog', '/sales', '/refunds'] as $suffix) {
        $this->get($this->demoUrl.$suffix)->assertNotFound();
    }
    expect(DB::table('website_orders')->count())->toBe(0)->and(DB::table('pos_payment_attempts')->count())->toBe(0)
        ->and(DB::table('pos_refunds')->count())->toBe(0)->and(DB::table('website_customers')->count())->toBe(0);
});

it('allows only the fixture page in production while the private POS service remains hard-disabled', function (): void {
    $fixture = PosFixture::merchant('production-browser-demo');
    app()->instance('env', 'production');
    try {
        $this->get($this->demoUrl)->assertOk()->assertSeeText('Alpha Printing');
        $this->get('https://localhost'.$this->demoPath)->assertNotFound();
        $this->get('https://127.0.0.1'.$this->demoPath)->assertNotFound();
        try {
            app(PosAccess::class)->register($fixture->tenant, $fixture->manager, $fixture->register->id);
            test()->fail('A public fictional page must not enable the private POS backend in production.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(404);
        }
    } finally {
        app()->instance('env', 'testing');
    }
    expect(DB::table('pos_payment_attempts')->count())->toBe(0)->and(DB::table('website_orders')->count())->toBe(0)
        ->and(config('pos.terminal_enabled'))->toBeFalse()->and(config('pos.live_payments_enabled'))->toBeFalse();
});
