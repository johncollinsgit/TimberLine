<?php

use App\Models\CustomerAccessRequest;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutVite();
});

test('public plans page renders tiers and add-ons', function (): void {
    $this->get(route('platform.plans'))
        ->assertOk()
        ->assertSee('data-premium-motion="public"', false)
        ->assertSeeText('Plans')
        ->assertSeeText('Add-ons')
        ->assertSeeText('Module Map')
        ->assertSeeText('Included now');
});

test('public demo request page renders access request form', function (): void {
    $this->get(route('platform.demo'))
        ->assertOk()
        ->assertSee('name="intent" value="demo"', false)
        ->assertSeeText('Request demo access')
        ->assertSeeText('Demo = evaluate safely')
        ->assertSeeText('Production = apply + activate')
        ->assertSeeText('Business type')
        ->assertSeeText('Already have access? Sign in');
});

test('public start as a client page renders plan interest inputs', function (): void {
    $this->get(route('platform.start'))
        ->assertOk()
        ->assertSee('name="intent" value="production"', false)
        ->assertSeeText('Production access request')
        ->assertSeeText('Commercial interest (optional)')
        ->assertSeeText('Preferred tier')
        ->assertSeeText('Add-ons of interest');
});

test('demo access request submission persists a pending request', function (): void {
    config()->set('tenancy.onboarding.demo_tenant_slug', 'demo');

    $this->post(route('platform.access-request'), [
        'intent' => 'demo',
        'name' => 'Demo User',
        'email' => 'demo-user@example.com',
        'company' => 'Demo Co',
        'message' => 'I want to see the demo workspace.',
    ])->assertRedirect(route('platform.request-submitted', ['intent' => 'demo'], absolute: false));

    $request = CustomerAccessRequest::query()->first();
    expect($request)->not->toBeNull();

    expect($request->intent)->toBe('demo')
        ->and($request->status)->toBe('pending')
        ->and($request->requested_tenant_slug)->toBe('demo');
    expect((string) data_get($request->metadata, 'preferred_plan_key', ''))->toBe('');
    expect((string) data_get($request->metadata, 'business_type', ''))->toBe('');

    $user = User::query()->where('email', 'demo-user@example.com')->first();
    expect($user)->not->toBeNull()
        ->and((bool) $user->is_active)->toBeFalse()
        ->and((string) $user->requested_via)->toBe('customer_demo');
});

test('demo request captures business context without production commercial writes', function (): void {
    config()->set('tenancy.onboarding.demo_tenant_slug', 'demo');

    $this->post(route('platform.access-request'), [
        'intent' => 'demo',
        'name' => 'Demo Context User',
        'email' => 'demo-context@example.com',
        'business_type' => 'electrician',
        'team_size' => '1_5',
        'timeline' => 'researching',
        'website' => 'https://demo-context.example.com',
        'preferred_plan_key' => 'growth',
        'addons_interest' => ['sms'],
    ])->assertRedirect(route('platform.request-submitted', ['intent' => 'demo'], absolute: false));

    $request = CustomerAccessRequest::query()->where('email', 'demo-context@example.com')->firstOrFail();

    expect((string) data_get($request->metadata, 'business_type'))->toBe('electrician')
        ->and((string) data_get($request->metadata, 'team_size'))->toBe('1_5')
        ->and((string) data_get($request->metadata, 'timeline'))->toBe('researching')
        ->and((string) data_get($request->metadata, 'website'))->toBe('https://demo-context.example.com')
        ->and((string) data_get($request->metadata, 'preferred_plan_key'))->toBe('growth')
        ->and((array) data_get($request->metadata, 'addons_interest'))->toBe(['sms']);
});

test('production access request submission preserves requested tenant slug', function (): void {
    $this->post(route('platform.access-request'), [
        'intent' => 'production',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
        'business_type' => 'landscaper',
        'team_size' => '6_20',
        'timeline' => '30_days',
        'website' => 'https://acme.example.com',
        'message' => 'Please approve us for production.',
    ])->assertRedirect(route('platform.request-submitted', ['intent' => 'production'], absolute: false));

    $request = CustomerAccessRequest::query()->where('email', 'ops@acme.example.com')->first();
    expect($request)->not->toBeNull()
        ->and($request->intent)->toBe('production')
        ->and($request->requested_tenant_slug)->toBe('acme')
        ->and((string) data_get($request->metadata, 'business_type'))->toBe('landscaper')
        ->and((string) data_get($request->metadata, 'team_size'))->toBe('6_20')
        ->and((string) data_get($request->metadata, 'timeline'))->toBe('30_days')
        ->and((string) data_get($request->metadata, 'website'))->toBe('https://acme.example.com');
});

test('duplicate public submissions reuse the existing pending request', function (): void {
    $this->post(route('platform.access-request'), [
        'intent' => 'production',
        'name' => 'Acme Ops',
        'email' => 'ops2@acme.example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
        'message' => 'First request.',
    ])->assertRedirect();

    $this->post(route('platform.access-request'), [
        'intent' => 'production',
        'name' => 'Acme Ops Updated',
        'email' => 'ops2@acme.example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
        'message' => 'Second request.',
    ])->assertRedirect();

    expect(CustomerAccessRequest::query()->count())->toBe(1);
    $request = CustomerAccessRequest::query()->first();
    expect((string) $request->name)->toBe('Acme Ops Updated')
        ->and((string) $request->message)->toBe('Second request.');
});
