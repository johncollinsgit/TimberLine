<?php

use App\Http\Middleware\WebsiteDomainRelay;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSiteVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set([
        'managed_website.relay.enabled' => true,
        'managed_website.relay.secret' => str_repeat('fictional-relay-key-', 3),
        'managed_website.editor_enabled' => true,
        'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true,
        'managed_website.custom_domains_enabled' => true,
        'managed_website.custom_domain_activation_enabled' => true,
        'managed_website.custom_domain_target' => 'sites.theeverbranch.com',
        'services.shopify.customer_account.client_id' => 'fictional-client',
    ]);
    foreach ([
        'tenant_sites' => '2026_07_27_120000_create_managed_website_tables.php',
        'tenant_site_versions' => '2026_07_27_200000_add_versioned_theme_and_media_to_tenant_sites.php',
        'tenant_site_domains' => '2026_07_27_230000_create_tenant_site_domains_table.php',
    ] as $table => $migration) {
        if (! Schema::hasTable($table)) {
            (require dirname(__DIR__, 3).'/database/migrations/'.$migration)->up();
        }
    }
});

function domainRelaySite(string $slug, string $hostname): array
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
    config()->set('managed_website.custom_domain_tenant_ids', [...config('managed_website.custom_domain_tenant_ids', []), $tenant->id]);
    $site = TenantSite::query()->create(['tenant_id' => $tenant->id, 'status' => 'published', 'public_enabled' => true, 'subdomain' => $slug]);
    $version = TenantSiteVersion::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'version_number' => 1, 'status' => 'published', 'settings' => ['theme_name' => $slug], 'navigation' => [], 'seo' => [], 'published_at' => now()]);
    $page = TenantSitePage::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'slug' => '/', 'page_type' => 'home', 'title' => $slug]);
    $pageVersion = TenantSitePageVersion::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'tenant_site_page_id' => $page->id, 'version_number' => 1, 'status' => 'published', 'title' => $slug, 'blocks' => [['type' => 'hero', 'heading' => 'Published '.$slug]], 'seo' => [], 'published_at' => now()]);
    $site->forceFill(['published_site_version_id' => $version->id])->save();
    $page->forceFill(['published_version_id' => $pageVersion->id])->save();
    $domain = TenantSiteDomain::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'hostname' => $hostname, 'status' => 'active', 'is_primary' => true, 'verification_token' => 'fictional-proof', 'verified_at' => now(), 'activated_at' => now()]);

    return [$tenant, $site, $page, $domain];
}

function domainRelayHeaders(string $host, string $method = 'GET', string $uri = '/', string $body = '', ?int $timestamp = null): array
{
    $timestamp ??= now()->timestamp;
    $message = implode("\n", ['v1', (string) $timestamp, $method, $host, $uri, hash('sha256', $body)]);

    return [
        'HTTP_X_EVERBRANCH_WEBSITE_HOST' => $host,
        'HTTP_X_EVERBRANCH_WEBSITE_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_EVERBRANCH_WEBSITE_SIGNATURE' => hash_hmac('sha256', $message, (string) config('managed_website.relay.secret')),
    ];
}

test('a signed relay restores exactly its published public tenant and host-local cookies', function (): void {
    domainRelaySite('relay-cedar', 'www.cedar.example');
    domainRelaySite('relay-maple', 'www.maple.example');
    $uri = '/?campaign=a%2Fb&value=%2B';
    $response = $this->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.cedar.example', 'GET', $uri));
    $response->assertOk()->assertSee('Published relay-cedar')->assertDontSee('Published relay-maple');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->headers->getCookies())->not->toBeEmpty();
    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getDomain())->toBeIn([null, '']);
    }
    $this->call('HEAD', 'https://app.theeverbranch.com/', [], [], [], domainRelayHeaders('www.cedar.example', 'HEAD'))->assertOk();
    Http::assertNothingSent();
});

test('tenant media stays on its own relayed host and is never publicly cached', function (): void {
    [$tenant, $site] = domainRelaySite('relay-media', 'www.media.example');
    domainRelaySite('relay-media-other', 'www.media-other.example');
    Storage::fake('local');
    Storage::disk('local')->put('relay-picture.txt', 'public image fixture');
    $media = TenantSiteMedia::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'storage_disk' => 'local', 'storage_path' => 'relay-picture.txt', 'file_name' => 'picture.txt', 'mime_type' => 'text/plain', 'file_size' => 20, 'checksum' => hash('sha256', 'public image fixture')]);
    $uri = '/website-media/'.$media->id;
    $response = $this->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.media.example', 'GET', $uri));
    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    foreach (['/website-media/', '/website-catalog-media/'] as $path) {
        $uri = $path.$media->id;
        $this->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.media-other.example', 'GET', $uri))->assertNotFound();
    }
});

test('ordinary canonical traffic needs no relay and disabled claims grant no original-host authority', function (): void {
    domainRelaySite('relay-off', 'www.off.example');
    config()->set('managed_website.relay.enabled', false);
    $this->get('https://app.theeverbranch.com/ready')->assertOk();
    $this->call('GET', 'https://app.theeverbranch.com/', [], [], [], domainRelayHeaders('www.off.example'))->assertNotFound();
    $this->withHeaders(['X-Forwarded-Host' => 'www.off.example'])->get('https://app.theeverbranch.com/ready')->assertOk();
});

test('the backend accepts the independent Node and WebCrypto protocol fixture', function (): void {
    domainRelaySite('relay-interop', 'shop.customer.test');
    config()->set('managed_website.relay.secret', 'fixture-only-relay-secret-32-bytes-long');
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1794690000));
    $request = Request::create('https://app.theeverbranch.com/catalog/a%2Fb%25?b=2&a=1&a=%2F');
    $request->headers->add([
        'X-Everbranch-Website-Host' => 'shop.customer.test',
        'X-Everbranch-Website-Timestamp' => '1794690000',
        'X-Everbranch-Website-Signature' => '34cd3e6bcb5a49c5f45a1a60e8890f581dde69528af496f03036b07ed29ade64',
    ]);
    $response = app(WebsiteDomainRelay::class)->handle($request, fn (Request $accepted) => response($accepted->getSchemeAndHttpHost()));
    expect($response->getContent())->toBe('https://shop.customer.test');
});

test('signed reads preserve a bare query delimiter and duplicate query order', function (string $uri): void {
    domainRelaySite('relay-query', 'www.query.example');
    $request = Request::create('https://app.theeverbranch.com'.$uri, server: domainRelayHeaders('www.query.example', 'GET', $uri));
    // Symfony's URL test factory discards an empty query delimiter. Model the
    // actual untouched Nginx REQUEST_URI instead of testing that normalization.
    $request->server->set('REQUEST_URI', $uri);
    $response = app(Kernel::class)->handle($request);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('Published relay-query');
})->with(['/?', '/?a=2&a=1&plus=%2B&slash=%2F']);

test('API routes without the web host guard cannot accept relay claims', function (): void {
    domainRelaySite('relay-api', 'www.api.example');
    Route::middleware('api')->get('/api/relay-readback', fn () => response('must not run'));
    foreach (['/api/relay-readback', '/%61pi/relay-readback'] as $uri) {
        $this->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.api.example', 'GET', $uri))->assertNotFound();
    }
});

test('the signature binds the host method path query and body', function (string $tamper): void {
    [, , $page] = domainRelaySite('relay-bound', 'www.bound.example');
    domainRelaySite('relay-other', 'www.other.example');
    $method = 'GET';
    $uri = '/?campaign=one';
    $body = '';
    $headers = domainRelayHeaders('www.bound.example', $method, $uri);
    if ($tamper === 'host') {
        $headers['HTTP_X_EVERBRANCH_WEBSITE_HOST'] = 'www.other.example';
    } elseif ($tamper === 'method') {
        $method = 'HEAD';
    } elseif ($tamper === 'path') {
        $uri = '/other?campaign=one';
    } elseif ($tamper === 'query') {
        $uri = '/?campaign=two';
    } elseif ($tamper === 'body') {
        $method = 'POST';
        $uri = '/website/forms/'.$page->id;
        $headers = domainRelayHeaders('www.bound.example', $method, $uri, 'message=original');
        $body = 'message=changed';
    }
    $this->call($method, 'https://app.theeverbranch.com'.$uri, [], [], [], $headers, $body)->assertNotFound();
})->with(['host', 'method', 'path', 'query', 'body']);

test('expired replay and future timestamps are rejected', function (int $offset): void {
    domainRelaySite('relay-expiry', 'www.expiry.example');
    $this->call('GET', 'https://app.theeverbranch.com/', [], [], [], domainRelayHeaders('www.expiry.example', timestamp: now()->timestamp + $offset))->assertNotFound();
})->with([-61, 6]);

test('signed unknown inactive and reserved hosts fail closed', function (string $kind): void {
    [, $site, , $domain] = domainRelaySite('relay-hosts', 'www.hosts.example');
    $host = $domain->hostname;
    if ($kind === 'unknown') {
        $host = 'www.unregistered.example';
    } elseif ($kind === 'inactive') {
        $domain->forceFill(['status' => 'verified'])->save();
    } elseif ($kind === 'private-site') {
        $site->forceFill(['public_enabled' => false])->save();
    } elseif ($kind === 'platform') {
        $host = 'app.theeverbranch.com';
    } elseif ($kind === 'first-party') {
        $host = 'relay-hosts.theeverbranch.com';
    } elseif ($kind === 'render-freeze') {
        config()->set('managed_website.public_render_enabled', false);
    } elseif ($kind === 'domain-freeze') {
        config()->set('managed_website.custom_domains_enabled', false);
    }
    $this->call('GET', 'https://app.theeverbranch.com/', [], [], [], domainRelayHeaders($host))->assertNotFound();
})->with(['unknown', 'inactive', 'private-site', 'platform', 'first-party', 'render-freeze', 'domain-freeze']);

test('the relay requires verified HTTPS on the canonical app ingress', function (string $origin): void {
    domainRelaySite('relay-ingress', 'www.ingress.example');
    $this->call('GET', $origin.'/', [], [], [], domainRelayHeaders('www.ingress.example'))->assertNotFound();
})->with(['http://app.theeverbranch.com', 'https://theeverbranch.com', 'https://unrelated.example']);

test('signed private routes and commerce writes never become a public relay surface', function (string $method, string $uri): void {
    domainRelaySite('relay-private', 'www.private.example');
    $this->call($method, 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.private.example', $method, $uri))->assertNotFound();
})->with([
    ['GET', '/auth/google/redirect'], ['GET', '/email/verify'], ['GET', '/landlord'],
    ['GET', '/ready'], ['GET', '/privacy'], ['GET', '/account'], ['GET', '/website'],
    ['POST', '/cart/items/1'], ['POST', '/checkout/website'], ['POST', '/webhooks/website-stripe'],
    ['DELETE', '/website/forms/1'],
]);

test('a signed form stays within its exact tenant site and retains CSRF protection', function (): void {
    [, , $ownPage] = domainRelaySite('relay-form', 'www.form.example');
    [, , $otherPage] = domainRelaySite('relay-form-other', 'www.form-other.example');
    $otherUri = '/website/forms/'.$otherPage->id;
    $this->call('POST', 'https://app.theeverbranch.com'.$otherUri, [], [], [], domainRelayHeaders('www.form.example', 'POST', $otherUri))->assertNotFound();
    $uri = '/website/forms/'.$ownPage->id;
    $this->call('POST', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.form.example', 'POST', $uri))->assertSessionHasErrors('name');
    app()->instance(ValidateCsrfToken::class, new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $this->call('POST', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.form.example', 'POST', $uri))->assertStatus(419);
});

test('oversize forms and GET bodies are rejected before public handlers', function (): void {
    [, , $page] = domainRelaySite('relay-size', 'www.size.example');
    $body = str_repeat('a', 1024 * 1024 + 1);
    $uri = '/website/forms/'.$page->id;
    $this->call('POST', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.size.example', 'POST', $uri, $body), $body)->assertNotFound();
    $this->call('GET', 'https://app.theeverbranch.com/', [], [], [], domainRelayHeaders('www.size.example', 'GET', '/', 'body'), 'body')->assertNotFound();
});

test('custom home ignores Shopify query and existing embedded page context', function (): void {
    domainRelaySite('relay-public-home', 'www.public-home.example');
    $uri = '/?shop=fake.myshopify.com&host=fake&hmac=fake';
    $this->withSession(['shopify_embedded_page_context' => ['shop_domain' => 'fake.myshopify.com']])
        ->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], domainRelayHeaders('www.public-home.example', 'GET', $uri))
        ->assertOk()->assertSee('Published relay-public-home');
});

test('readiness exposes relay booleans without secrets and only reads the provider', function (): void {
    config()->set('managed_website.cloudflare', ['enabled' => true, 'zone_id' => str_repeat('a', 32), 'api_token' => 'fictional-provider-token']);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['status' => 'active', 'origin' => 'sites.theeverbranch.com']])]);
    expect(Artisan::call('website:domain-readiness', ['--provider' => true]))->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true))->toMatchArray(['relay_enabled' => true, 'relay_secret_configured' => true, 'cloudflare_fallback_active' => true]);
    expect($output)->not->toContain(config('managed_website.relay.secret'), 'fictional-provider-token');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/custom_hostnames/fallback_origin'));
    Http::assertSentCount(1);
    config()->set('managed_website.relay.secret', 'short');
    expect(Artisan::call('website:domain-readiness'))->toBe(1);
});
