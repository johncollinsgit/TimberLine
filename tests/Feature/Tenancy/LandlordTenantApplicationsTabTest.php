<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('landlord tenant applications tab shows wholesale submissions linked by tenant slug', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry Wholesale',
        'slug' => 'modern-forestry-wholesale',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'John Buyer',
        'email' => 'johnbuyer@example.com',
        'company' => 'Cedar Mercantile',
        'requested_tenant_slug' => 'modern-forestry-wholesale',
        'message' => 'Please review our wholesale application.',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=applications")
        ->assertOk()
        ->assertSeeText('Tenant Applications')
        ->assertSeeText('John Buyer')
        ->assertSeeText('Wholesale application')
        ->assertSeeText('Cedar Mercantile')
        ->assertSee('/admin/wholesale/applications/', false);
});
