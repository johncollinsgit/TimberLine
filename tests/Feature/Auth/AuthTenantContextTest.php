<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [
        'backstage.theforestrystudio.com',
        'theforestrystudio.com',
    ]);
    config()->set('tenancy.auth.host_map', []);
});

test('tenant resolves from subdomain host on guest login route', function (): void {
    Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $response = $this->get('http://acme.backstage.local/login');

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

test('missing tenant host fails soft on login route', function (): void {
    $response = $this->get('http://unknown.local/login');

    $response->assertOk();
    $response->assertViewHas('authTenantContext', function (array $context): bool {
        return ! (bool) ($context['resolved'] ?? true)
            && ($context['classification'] ?? null) === 'none';
    });
});

test('flagship host resolves modern forestry flagship presentation path', function (): void {
    Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $response = $this->get('http://backstage.theforestrystudio.com/login');

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

    $response = $this->get('http://acme.backstage.local/login');

    $response->assertOk();
    $response->assertSee('Acme Candle Co', false);
    $response->assertSee('Tenant Console', false);
});

test('login submit path still works when no tenant is resolved', function (): void {
    $response = $this->post('http://unknown.local/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-right-password',
        ]);

    $response->assertSessionHasErrors('email');
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

    $this->post('http://acme.backstage.local/login', [
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

    Log::shouldReceive('debug')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'auth.tenant_context.resolved'
                && ($context['route_name'] ?? null) === 'auth.google.redirect'
                && ($context['classification'] ?? null) === 'flagship';
        });
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'auth.google.oauth.preflight_failed'
                && ($context['phase'] ?? null) === 'redirect';
        });

    $this->get('http://backstage.theforestrystudio.com/auth/google/redirect')
        ->assertRedirect(route('login'));

});
