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
        ->assertSeeText('Add-ons');
});

test('public demo request page renders access request form', function (): void {
    $this->get(route('platform.demo'))
        ->assertOk()
        ->assertSee('name="intent" value="demo"', false)
        ->assertSeeText('Request demo access')
        ->assertSeeText('Already have access? Sign in');
});

test('public start as a client page renders plan interest inputs', function (): void {
    $this->get(route('platform.start'))
        ->assertOk()
        ->assertSee('name="intent" value="production"', false)
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

    $user = User::query()->where('email', 'demo-user@example.com')->first();
    expect($user)->not->toBeNull()
        ->and((bool) $user->is_active)->toBeFalse()
        ->and((string) $user->requested_via)->toBe('customer_demo');
});

test('production access request submission preserves requested tenant slug', function (): void {
    $this->post(route('platform.access-request'), [
        'intent' => 'production',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
        'message' => 'Please approve us for production.',
    ])->assertRedirect(route('platform.request-submitted', ['intent' => 'production'], absolute: false));

    $request = CustomerAccessRequest::query()->where('email', 'ops@acme.example.com')->first();
    expect($request)->not->toBeNull()
        ->and($request->intent)->toBe('production')
        ->and($request->requested_tenant_slug)->toBe('acme');
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
