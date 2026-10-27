<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['app.url' => 'https://app.theeverbranch.com', 'enterprise.reviewer_demo_enabled' => true,
        'tenancy.domains.canonical.base_domain' => 'theeverbranch.com', 'tenancy.domains.canonical.public_host' => 'theeverbranch.com',
        'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com', 'tenancy.domains.legacy.base_domains' => [],
        'tenancy.domains.legacy.public_hosts' => [], 'tenancy.domains.legacy.landlord_hosts' => [],
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com', 'theeverbranch.com'], 'tenancy.auth.host_map' => [],
        'session.driver' => 'database', 'cache.default' => 'array']);
    app('session')->forgetDrivers();
});

test('shared Enterprise reviewer renderer keeps anonymous public SQL cookie and provider isolation', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $response = $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae')->assertOk()
            ->assertSee('EverbranchSampleTransport', false)->assertSeeText('SAMPLE DATA')->assertSeeText('Connect Google Workspace');
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toBe([])->and($response->headers->getCookies())->toBe([]);
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("connect-src 'none'", "form-action 'none'", "frame-ancestors 'none'")->not->toContain('unsafe-eval');
    preg_match("/script-src 'nonce-([^']+)'/", $csp, $nonce);
    preg_match_all('/<(script|style)\b([^>]*)>/i', $response->getContent(), $elements, PREG_SET_ORDER);
    foreach ($elements as $element) {
        expect($element[2])->toContain('nonce="'.$nonce[1].'"')->not->toMatch('/\bsrc\s*=/i');
    }
    expect($response->getContent())->not->toContain('X-Team-Viewer: 1', 'sk_live_', 'john@evergrovesoftware.com');
    Http::assertNothingSent();
});

test('public reviewer route rejects tenant query authority other hosts and operational mutations', function (): void {
    foreach (['?tenant=college', '?runtime=private', '?area_id=10000000-0000-4000-8000-000000000001'] as $query) {
        $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae'.$query)->assertNotFound();
    }
    $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows?project_id=anything')->assertNotFound();
    $this->postJson('https://app.theeverbranch.com/platform/demos/lees-mcrae', ['action' => 'request.create'])->assertStatus(405);
    $this->postJson('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows', ['action' => 'run'])->assertStatus(405);
    $this->get('https://theeverbranch.com/platform/demos/lees-mcrae')->assertNotFound();
    Http::assertNothingSent();
});

test('sample workflow remains unavailable by default and preserves existing fictional demo', function (): void {
    config(['enterprise.reviewer_demo_enabled' => false]);
    $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows')->assertNotFound();
    $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae')->assertOk()->assertSee('lees-mcrae-fictional-hosted-v3', false);
    Http::assertNothingSent();
});

test('unpublished public workflow keeps its 404 with an isolated path back to the available demo', function (): void {
    config(['enterprise.reviewer_demo_enabled' => false]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $response = $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows')->assertNotFound()
            ->assertSeeText('This workflow preview is not published.')
            ->assertSee('href="/platform/demos/lees-mcrae"', false)
            ->assertDontSee('enterprise-workflow', false)->assertDontSee('<script', false);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toBe([])->and($response->headers->getCookies())->toBe([]);
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'", "script-src 'none'", "connect-src 'none'", "form-action 'none'", "frame-ancestors 'none'");
    preg_match("/style-src 'nonce-([^']+)'/", $csp, $nonce);
    expect($response->getContent())->toContain('style nonce="'.$nonce[1].'"')
        ->not->toContain('/enterprise-campus', '/enterprise-owner', 'john@evergrovesoftware.com');
    $this->head('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows')->assertNotFound();
    $this->postJson('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows', ['action' => 'run'])->assertStatus(405);
    Http::assertNothingSent();
});

test('workflow availability help never replaces host or query authority denials', function (): void {
    config(['enterprise.reviewer_demo_enabled' => false]);
    foreach (['?tenant=college', '?project_id=anything', '?runtime=private'] as $query) {
        $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae/workflows'.$query)
            ->assertNotFound()->assertDontSeeText('This workflow preview is not published.');
    }
    $this->get('https://theeverbranch.com/platform/demos/lees-mcrae/workflows')
        ->assertNotFound()->assertDontSeeText('This workflow preview is not published.');
    Http::assertNothingSent();
});
