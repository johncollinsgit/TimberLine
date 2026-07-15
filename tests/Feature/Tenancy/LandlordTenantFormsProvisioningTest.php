<?php

use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\User;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('landlord operator can provision wholesale application form for a tenant', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry Wholesale',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/tenants/{$tenant->id}/forms/templates/wholesale_application")
        ->assertRedirect(route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'applications'], absolute: false));

    $form = TenantForm::query()->where('tenant_id', $tenant->id)->where('slug', 'wholesale-application')->first();

    expect($form)->not->toBeNull()
        ->and((string) $form->channel)->toBe('wholesale_storefront')
        ->and((string) $form->status)->toBe('active');
});
