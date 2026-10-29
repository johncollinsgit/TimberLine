<?php

use App\Models\FieldServiceFinancialDocument;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('field invoice preview is tenant scoped, read only, and never invokes a real provider', function (): void {
    Http::preventStrayRequests();

    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $imported = FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'quickbooks',
        'document_type' => 'invoice',
        'external_id' => 'quickbooks:invoice:existing-123',
        'document_number' => 'QB-123',
        'status' => 'open',
        'total_amount' => 125.00,
        'balance' => 125.00,
    ]);

    $response = $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'customer_email' => 'customer@example.test',
        'invoice_provider' => 'everbranch_pay',
        'line_items' => [
            ['description' => 'Service call', 'quantity' => 2, 'unit_amount_cents' => 12500],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('preview.issued', false)
        ->assertJsonPath('preview.persisted', false)
        ->assertJsonPath('preview.invoice.number', 'PREVIEW')
        ->assertJsonPath('preview.invoice.total_cents', 25000)
        ->assertJsonPath('preview.tax.status', 'not_calculated')
        ->assertJsonPath('preview.invoice_provider.key', 'everbranch_pay')
        ->assertJsonPath('preview.invoice_provider.status', 'setup_unverified')
        ->assertJsonPath('preview.payment_processing.processor', 'stripe')
        ->assertJsonPath('preview.payment_processing.payment_link_created', false)
        ->assertJsonPath('preview.delivery.status', 'not_sent')
        ->assertJsonPath('preview.accounting_sync.status', 'not_selected');

    expect(FieldServiceFinancialDocument::query()->count())->toBe(1)
        ->and(FieldServiceFinancialDocument::query()->first()->is($imported))->toBeTrue()
        ->and($tenant->brandProfile()->exists())->toBeFalse();
    Http::assertNothingSent();
    $this->postJson('/api/mobile/v1/workspaces/other-workspace/field-service/invoices/preview', [
        'customer_name' => 'Other Customer',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertNotFound();
});

test('field invoice preview is limited to tenant financial administrators', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    $employee = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'invoice_provider' => 'quickbooks',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertForbidden();
});

test('field invoice preview is unavailable when field service is explicitly disabled', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'unavailable', 'enabled_status' => 'disabled', 'entitlement_source' => 'test']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'invoice_provider' => 'everbranch_pay',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertForbidden();
});

test('quickbooks can be selected as invoice provider without writing to accounting', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'invoice_provider' => 'quickbooks',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertOk()
        ->assertJsonPath('preview.invoice_provider.key', 'quickbooks')
        ->assertJsonPath('preview.accounting_sync.provider', 'quickbooks')
        ->assertJsonPath('preview.accounting_sync.status', 'not_synced')
        ->assertJsonPath('preview.payment_processing.processor', null)
        ->assertJsonPath('preview.payment_processing.status', 'not_selected')
        ->assertJsonPath('preview.invoice_provider.invoice_created', false);
    Http::assertNothingSent();
});
