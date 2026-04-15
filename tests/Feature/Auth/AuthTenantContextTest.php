<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
    config()->set('tenancy.auth.flagship_hosts', [
        'app.theeverbranch.com',
        'theeverbranch.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
});

test('tenant resolves from subdomain host on guest login route', function (): void {
    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $response = $this->get('http://acme.theeverbranch.com/login');

    $response->assertOk();
    $response->assertViewHas('authTenantContext', function (array $context): bool {
        return (bool) ($context['resolved'] ?? false)
            && ($context['classification'] ?? null) === 'generic'
            && data_get($context, 'tenant.slug') === 'acme';
    });
    $response->assertViewHas('authTenantPresentation', function (array $presentation): bool {
        return ($presentation['variant'] ?? null) === 'generic'
            && ($presentation['tenant_label'] ?? null) === 'Acme Candle Co';
    });
});

test('unknown hosts are rejected on login route', function (): void {
    $this->get('http://unknown.local/login')->assertNotFound();
});

test('flagship host resolves modern forestry flagship presentation path', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $response = $this->get('http://theeverbranch.com/login');

    $response->assertOk();
    $response->assertViewHas('authTenantContext', function (array $context): bool {
        return (bool) ($context['resolved'] ?? false)
            && ($context['classification'] ?? null) === 'flagship'
            && data_get($context, 'tenant.slug') === 'modern-forestry';
    });
    $response->assertViewHas('authTenantPresentation', function (array $presentation): bool {
        return ($presentation['variant'] ?? null) === 'flagship'
            && ($presentation['tenant_label'] ?? null) === 'Modern Forestry';
    });
    $response->assertSee('Operations Console', false);
});

test('non flagship tenant gets safe generic auth presentation', function (): void {
    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $response = $this->get('http://acme.theeverbranch.com/login');

    $response->assertOk();
    $response->assertSee('Acme Candle Co', false);
    $response->assertSee('Tenant Console', false);
});

test('login submit path is rejected when host is not canonical', function (): void {
    $this->post('http://unknown.local/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-right-password',
        ])
        ->assertNotFound();
});

test('guest auth submit path logs tenant resolution diagnostics', function (): void {
    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    Log::shouldReceive('debug')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'auth.tenant_context.resolved'
                && ($context['route_name'] ?? null) === 'login.store'
                && ($context['classification'] ?? null) === 'generic';
        });

    $this->post('http://acme.theeverbranch.com/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-right-password',
        ])
        ->assertSessionHasErrors('email');

});

test('google redirect route receives auth tenant context middleware', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    config()->set('services.google.enabled', false);
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');
    config()->set('services.google.redirect', '');

    Log::spy();

    $response = $this->get('http://app.theeverbranch.com/auth/google/redirect');

    $response->assertRedirect();

    $location = (string) ($response->headers->get('Location') ?? '');
    expect(parse_url($location, PHP_URL_HOST))->toBe('app.theeverbranch.com')
        ->and(parse_url($location, PHP_URL_PATH))->toBe('/login');

    Log::shouldHaveReceived('debug')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'auth.tenant_context.resolved'
                && ($context['route_name'] ?? null) === 'auth.google.redirect'
                && ($context['host'] ?? null) === 'app.theeverbranch.com';
        })
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'auth.google.oauth.preflight_failed'
                && ($context['phase'] ?? null) === 'redirect';
        })
        ->once();

});
