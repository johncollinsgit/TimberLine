<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\PosFixture;

function leesMcRaeDemoBodyWithoutNonce(string $html): string
{
    return preg_replace('/nonce=(["\'])[^"\']+\1/', 'nonce="[request-nonce]"', $html);
}

function leesMcRaeDemoCanonicalCounts(): array
{
    $counts = [];
    foreach (['users', 'tenants', 'tenant_user', 'sessions', 'team_projects', 'team_tasks', 'team_project_members',
        'team_task_projects', 'team_work_events', 'team_work_receipts', 'team_file_uploads', 'team_file_chunks',
        'team_operation_records', 'team_operation_attempts', 'tenant_support_tickets', 'tenant_support_ticket_messages',
        'website_customers', 'website_orders', 'website_payments', 'tenant_payment_accounts',
        'pos_registers', 'pos_payment_attempts', 'pos_refunds'] as $table) {
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
    $this->campusDemoPath = '/platform/demos/lees-mcrae';
    $this->campusDemoUrl = 'https://app.theeverbranch.com'.$this->campusDemoPath;
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('serves fixed fictional campus content without tenant reads business writes session cookies or scheduler activity', function (): void {
    expect(route('platform.lees-mcrae-campus-demo', [], false))->toBe($this->campusDemoPath);
    Cache::forever('scheduler:last_heartbeat', now()->subHour()->toIso8601String());
    Cache::forget('scheduler:heartbeat_check_lock');
    $before = leesMcRaeDemoCanonicalCounts();
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $first = $this->get($this->campusDemoUrl)->assertOk()->assertSeeText('Lees-McRae');
        $second = $this->get($this->campusDemoUrl)->assertOk();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(strtolower($first->getContent()))->toContain('fictional')->toContain('simulation')
        ->not->toContain('creates a real task in the reused teams engine')
        ->toContain('the hosted demo does not execute the native teams engine')
        ->and($queries)->toBe([])
        ->and($first->headers->getCookies())->toBe([])->and($second->headers->getCookies())->toBe([])
        ->and(leesMcRaeDemoCanonicalCounts())->toBe($before)
        ->and(Cache::has('scheduler:heartbeat_check_lock'))->toBeFalse();
    $first->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->headers->get('X-Robots-Tag'))->toContain('noindex')->toContain('nofollow')->toContain('noarchive');
    $csp = (string) $first->headers->get('Content-Security-Policy');
    foreach (["default-src 'none'", "connect-src 'none'", "form-action 'none'", "frame-ancestors 'none'",
        "base-uri 'none'", 'img-src data:', "style-src-attr 'unsafe-inline'"] as $directive) {
        expect($csp)->toContain($directive);
    }
    expect(preg_match("/script-src 'nonce-([^']+)'/", $csp, $nonce))->toBe(1)
        ->and(strlen($nonce[1]))->toBeGreaterThanOrEqual(22)
        ->and($csp)->toContain("style-src 'nonce-".$nonce[1]."'")->not->toContain('unsafe-eval')
        ->not->toMatch("/(?:^|;)\\s*script-src\\s[^;]*'unsafe-inline'/")
        ->not->toMatch("/(?:^|;)\\s*style-src\\s[^;]*'unsafe-inline'/")
        ->and($second->headers->get('Content-Security-Policy'))->not->toContain($nonce[1]);
    preg_match_all('/<(script|style)\b([^>]*)>/i', $first->getContent(), $elements, PREG_SET_ORDER);
    expect($elements)->not->toBeEmpty();
    foreach ($elements as $element) {
        expect($element[2])->toContain('nonce="'.$nonce[1].'"')->not->toMatch('/\bsrc\s*=/i');
    }
    expect(leesMcRaeDemoBodyWithoutNonce($second->getContent()))->toBe(leesMcRaeDemoBodyWithoutNonce($first->getContent()));
});

it('renders the same fictional bytes for a signed-in user without reading or exposing their workspace or session', function (): void {
    $fixture = PosFixture::merchant('private-campus-demo');
    $fixture->tenant->update(['name' => 'PRIVATE-CAMPUS-TENANT-MARKER']);
    $fixture->product->update(['title' => 'PRIVATE-CAMPUS-PRODUCT-MARKER']);
    $fixture->manager->update(['name' => 'PRIVATE-CAMPUS-USER-MARKER']);
    config()->set('services.stripe.secret', 'sk_test_FICTIONAL_PRIVATE_CAMPUS_MARKER');
    $sessionId = Str::random(40);
    DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $fixture->manager->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Fictional existing campus browser session', 'payload' => base64_encode(serialize(['private' => 'PRIVATE-CAMPUS-SESSION-MARKER'])),
        'last_activity' => now()->subMinutes(5)->timestamp]);
    $before = leesMcRaeDemoCanonicalCounts();
    $sessionBefore = DB::table('sessions')->where('id', $sessionId)->first();
    $anonymous = $this->get($this->campusDemoUrl)->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $authenticated = $this->actingAs($fixture->manager)->withCookie(config('session.cookie'), $sessionId)
            ->get($this->campusDemoUrl)->assertOk();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(leesMcRaeDemoBodyWithoutNonce($authenticated->getContent()))->toBe(leesMcRaeDemoBodyWithoutNonce($anonymous->getContent()))
        ->and($queries)->toBe([])->and($authenticated->headers->getCookies())->toBe([])
        ->and(auth()->id())->toBe($fixture->manager->id)->and(leesMcRaeDemoCanonicalCounts())->toBe($before)
        ->and(DB::table('sessions')->where('id', $sessionId)->first())->toEqual($sessionBefore);
    foreach (['PRIVATE-CAMPUS-TENANT-MARKER', 'PRIVATE-CAMPUS-PRODUCT-MARKER', 'PRIVATE-CAMPUS-USER-MARKER',
        'PRIVATE-CAMPUS-SESSION-MARKER', 'sk_test_FICTIONAL_PRIVATE_CAMPUS_MARKER'] as $marker) {
        $authenticated->assertDontSee($marker, false);
        $anonymous->assertDontSee($marker, false);
    }
});

it('removes retained Livewire assets only from the standalone campus response', function (): void {
    app('livewire')->forceAssetInjection();
    $page = $this->get($this->campusDemoUrl)->assertOk();
    expect($page->getContent())->not->toContain('Livewire Styles')->not->toContain('data-update-uri=');
    Route::get('/fixture-campus-demo-livewire-next', fn () => response('<html><head></head><body>Fixture</body></html>'));
    app('livewire')->forceAssetInjection();
    $this->get('https://app.theeverbranch.com/fixture-campus-demo-livewire-next')->assertOk()
        ->assertSee('Livewire Styles', false)->assertSee('data-update-uri=', false);
    app('livewire')->flushState();
});

it('rejects all query-based identity credentials callbacks or fixture overrides', function (): void {
    $before = leesMcRaeDemoCanonicalCounts();
    foreach (['tenant_id=16', 'persona=admin', 'job_id=J05', 'token=PRIVATE-CAMPUS-QUERY-MARKER',
        'secret=PRIVATE-CAMPUS-QUERY-MARKER', 'callback=https%3A%2F%2Fevil.example.test',
        'fixture%5Brequest%5D=PRIVATE-CAMPUS-QUERY-MARKER', 'unused=', 'nonce=PRIVATE-CAMPUS-QUERY-MARKER'] as $query) {
        $this->get($this->campusDemoUrl.'?'.$query)->assertNotFound()->assertDontSee('PRIVATE-CAMPUS-QUERY-MARKER', false);
    }
    $this->call('GET', $this->campusDemoUrl.'?&&', [], [], [], ['QUERY_STRING' => '&&'])->assertNotFound();
    $after = leesMcRaeDemoCanonicalCounts();
    // Application error pages may maintain their ordinary session; business records cannot change.
    unset($before['sessions'], $after['sessions']);
    expect($after)->toBe($before);
});

it('permits only the configured landlord host and explicit development loopback hosts', function (): void {
    foreach (['app.theeverbranch.com' => 200, 'localhost' => 200, '127.0.0.1' => 200,
        'theeverbranch.com' => 404, 'untrusted.example.test' => 404, 'other.localhost' => 404,
        'app.theeverbranch.com.evil.test' => 404] as $host => $status) {
        $this->get('https://'.$host.$this->campusDemoPath)->assertStatus($status);
    }
    $fixture = PosFixture::merchant('campus-host-boundary');
    $this->get('https://'.$fixture->site->subdomain.'.theeverbranch.com'.$this->campusDemoPath)->assertNotFound();
    config()->set('tenancy.domains.canonical.landlord_host', 'campus-entry.theeverbranch.com');
    $this->get('https://campus-entry.theeverbranch.com'.$this->campusDemoPath)->assertOk();
    $this->get($this->campusDemoUrl)->assertNotFound();
});

it('supports HEAD and never accepts server mutations or exposes a nested demo API', function (): void {
    $before = leesMcRaeDemoCanonicalCounts();
    $head = $this->head($this->campusDemoUrl)->assertOk()->assertContent('');
    expect($head->headers->getCookies())->toBe([]);
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $this->call($method, $this->campusDemoUrl, ['tenant_id' => 16, 'action' => 'assign'])->assertStatus(405);
    }
    foreach (['/api', '/api/state', '/api/operations', '/api/intake', '/api/review', '/files', '/tasks'] as $suffix) {
        $this->get($this->campusDemoUrl.$suffix)->assertNotFound();
    }
    $after = leesMcRaeDemoCanonicalCounts();
    unset($before['sessions'], $after['sessions']);
    expect($after)->toBe($before);
});

it('does not replace sign-in private Teams commands or the existing POS demo', function (): void {
    $this->getJson('https://app.theeverbranch.com/team/data')->assertUnauthorized();
    $this->postJson('https://app.theeverbranch.com/team/commands', ['kind' => 'task.create', 'title' => 'PRIVATE-CAMPUS-WRITE-MARKER'])
        ->assertUnauthorized();
    $this->get($this->campusDemoUrl)->assertOk();
    $this->getJson('https://app.theeverbranch.com/team/data')->assertUnauthorized();
    $this->get('https://app.theeverbranch.com/platform/demos/alpha-printing-pos')->assertOk()->assertSeeText('Alpha Printing');
    expect(DB::table('team_tasks')->count())->toBe(0)->and(DB::table('team_projects')->count())->toBe(0)
        ->and(config('pos.terminal_enabled'))->toBeFalse()->and(config('pos.live_payments_enabled'))->toBeFalse();
});

it('keeps only the canonical fictional document available in production', function (): void {
    app()->instance('env', 'production');
    try {
        $this->get($this->campusDemoUrl)->assertOk()->assertSeeText('Lees-McRae');
        $this->get('https://localhost'.$this->campusDemoPath)->assertNotFound();
        $this->get('https://127.0.0.1'.$this->campusDemoPath)->assertNotFound();
        $this->getJson('https://app.theeverbranch.com/team/data')->assertUnauthorized();
    } finally {
        app()->instance('env', 'testing');
    }
});
