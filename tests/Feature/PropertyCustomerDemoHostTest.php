<?php

use App\Http\Middleware\ServePropertyCustomerDemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->originalPublicPath = public_path();
    $this->fixtureRoot = storage_path('framework/testing/property-demo-'.Str::uuid());
    File::makeDirectory($this->fixtureRoot.'/property-demo', 0755, true);
    app()->usePublicPath($this->fixtureRoot);
    File::put(public_path('property-demo/index.html'), '<!doctype html><html><body>Fictional property demo<script src="/app.js"></script></body></html>');
    File::put(public_path('property-demo/app.js'), 'window.fictionalPropertyDemo = true;');
    File::put(public_path('property-demo/manifest.webmanifest'), '{"display":"standalone"}');
    config()->set('property_customer_demo', [
        'hosts' => ['property.theeverbranch.com', 'everbranchproperties.com', 'www.everbranchproperties.com'],
        'assets' => [
            '/' => ['file' => 'index.html', 'mime' => 'text/html; charset=utf-8'],
            '/app.js' => ['file' => 'app.js', 'mime' => 'application/javascript'],
            '/manifest.webmanifest' => ['file' => 'manifest.webmanifest', 'mime' => 'application/manifest+json'],
        ],
    ]);
});

afterEach(function () {
    app()->usePublicPath($this->originalPublicPath);
    File::deleteDirectory($this->fixtureRoot);
});

it('serves the anonymous static demo only on its three explicit hosts', function (string $host) {
    $this->get('https://'.$host.'/')->assertOk()->assertSee('Fictional property demo');
    $this->get('https://'.$host.'/?portal=1')->assertOk()->assertSee('Fictional property demo');
    $this->get('https://'.$host.'/app.js')->assertOk()->assertHeader('Content-Type', 'application/javascript');
    $this->get('https://'.$host.'/manifest.webmanifest')->assertOk()->assertSee('standalone');
})->with(['property.theeverbranch.com', 'everbranchproperties.com', 'www.everbranchproperties.com']);

it('never enters database or session middleware and applies isolated response headers', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->withCookie(config('session.cookie'), 'FICTIONAL-PRIVATE-SESSION-MARKER')
        ->get('https://property.theeverbranch.com/?portal=1');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    $response->assertOk()->assertDontSee('FICTIONAL-PRIVATE-SESSION-MARKER')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect($response->headers->getCookies())->toBe([]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'", "script-src 'self'", "frame-ancestors 'none'", "img-src 'self' data: blob:")
        ->not->toContain('unsafe-eval');
});

it('returns HEAD metadata without an asset body', function () {
    $response = $this->call('HEAD', 'https://property.theeverbranch.com/app.js');
    $response->assertOk()->assertContent('')->assertHeader('Content-Length', (string) strlen('window.fictionalPropertyDemo = true;'));
});

it('closes non-demo paths and unrecognized query strings before tenant resolution', function (string $path) {
    $response = $this->get('https://property.theeverbranch.com'.$path);
    $response->assertNotFound()->assertContent('');
    expect($response->headers->getCookies())->toBe([]);
})->with([
    '/login', '/logout', '/dashboard', '/ready', '/up', '/api/demo', '/api/files/private-file',
    '/settings', '/.env', '/index.php', '/unknown', '/%2e%2e/.env', '/%252e%252e/.env',
    '/?tenant=private', '/?portal=0', '/?portal=1&tenant=private', '/app.js?portal=1',
]);

it('rejects writes and preflights without running any application action', function (string $method) {
    $response = $this->call($method, 'https://property.theeverbranch.com/api/demo', ['action' => 'message']);
    $response->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')->assertContent('');
    expect($response->headers->getCookies())->toBe([]);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('rejects invalid mappings and files outside the curated static root', function () {
    File::put(public_path('outside.txt'), 'PRIVATE-FIXTURE');
    File::put(public_path('property-demo/unsafe.php'), '<?php echo "PRIVATE-FIXTURE";');
    foreach ([
        '/outside' => ['file' => '../outside.txt', 'mime' => 'text/plain'],
        '/php' => ['file' => 'unsafe.php', 'mime' => 'text/plain'],
        '/missing' => ['file' => 'missing.txt', 'mime' => 'text/plain'],
    ] as $path => $asset) {
        config()->set('property_customer_demo.assets.'.$path, $asset);
        $this->get('https://property.theeverbranch.com'.$path)->assertNotFound()->assertContent('');
    }
});

it('rejects a symlink escaping the curated static root', function () {
    File::put(public_path('outside.txt'), 'PRIVATE-FIXTURE');
    if (! @symlink(public_path('outside.txt'), public_path('property-demo/escape.txt'))) {
        $this->markTestSkipped('Creating symlinks is unavailable on this host.');
    }
    config()->set('property_customer_demo.assets', [
        ...config('property_customer_demo.assets'),
        '/escape.txt' => ['file' => 'escape.txt', 'mime' => 'text/plain'],
    ]);
    $this->get('https://property.theeverbranch.com/escape.txt')->assertNotFound()->assertContent('');
});

it('does not let a forwarded host bypass the demo boundary', function () {
    $this->withHeader('X-Forwarded-Host', 'app.theeverbranch.com')
        ->get('https://property.theeverbranch.com/api/demo')->assertNotFound()->assertContent('');
});

it('leaves unrelated hosts untouched even when forwarded headers name the demo', function (string $host) {
    $request = Request::create('https://'.$host.'/?tenant=existing', 'POST');
    $request->headers->set('X-Forwarded-Host', 'property.theeverbranch.com');
    $response = app(ServePropertyCustomerDemo::class)->handle($request, fn () => new Response('existing application', 202));
    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('existing application')
        ->and($response->headers->has('X-Robots-Tag'))->toBeFalse();
})->with([
    'app.theeverbranch.com', 'theeverbranch.com', 'modern-forestry.theeverbranch.com',
    'unknown.example', 'property.theeverbranch.com.evil.example', 'everbranchproperties.com.evil.example', 'grovebud.com',
]);
