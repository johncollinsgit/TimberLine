<?php

use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingImportRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    config()->set('marketing.square.enabled', true);
    config()->set('marketing.square.sync_customers_enabled', false);
    config()->set('marketing.square.sync_orders_enabled', false);
    config()->set('marketing.square.sync_payments_enabled', false);
});

test('admin and marketing manager can access providers integrations tooling', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $mapping = MarketingEventSourceMapping::query()->create([
        'tenant_id' => $tenant->id,
        'source_system' => 'square_tax_name',
        'raw_value' => 'county 7%',
        'normalized_value' => 'county 7%',
        'is_active' => true,
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $admin->tenants()->syncWithoutDetaching([$tenant->id]);
    $marketingManager->tenants()->syncWithoutDetaching([$tenant->id]);

    foreach ([$admin, $marketingManager] as $user) {
        $this->actingAs($user)
            ->get(route('marketing.providers-integrations'))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('marketing.providers-integrations.shopify-customer-sync-health'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('marketing.providers-integrations.mappings.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('marketing.providers-integrations.mappings.edit', $mapping))
            ->assertOk();
    }
});

test('unauthorized roles cannot access providers integrations tooling', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Unauthorized Access Tenant',
        'slug' => 'unauthorized-access-tenant',
    ]);

    $mapping = MarketingEventSourceMapping::query()->create([
        'tenant_id' => $tenant->id,
        'source_system' => 'square_source_name',
        'raw_value' => 'Flowertown',
        'is_active' => true,
    ]);

    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('marketing.providers-integrations'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.providers-integrations.shopify-customer-sync-health'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.providers-integrations.mappings.create'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.providers-integrations.mappings.edit', $mapping))
        ->assertForbidden();
});

test('marketing manager can create mapping and run legacy import action', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.mappings.store'), [
            'source_system' => 'square_source_name',
            'raw_value' => 'Florida Strawberry Festival',
            'normalized_value' => 'florida strawberry festival',
            'is_active' => 1,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $csv = "contact_id,email\nlegacy-1,legacy@example.com\n";
    $file = UploadedFile::fake()->createWithContent('legacy.csv', $csv);

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.import-legacy'), [
            'import_type' => 'yotpo_contacts_import',
            'file' => $file,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));
});

test('providers integrations recent import runs are tenant scoped', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Integrations Tenant A',
        'slug' => 'integrations-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Integrations Tenant B',
        'slug' => 'integrations-tenant-b',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenantA->id]);

    MarketingImportRun::query()->create([
        'tenant_id' => $tenantA->id,
        'type' => 'square_customers_sync',
        'status' => 'completed',
        'source_label' => 'tenant-a-run',
        'started_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(10),
        'summary' => ['processed' => 10, 'errors' => 0],
    ]);

    MarketingImportRun::query()->create([
        'tenant_id' => $tenantB->id,
        'type' => 'square_customers_sync',
        'status' => 'completed',
        'source_label' => 'tenant-b-run',
        'started_at' => now()->subMinutes(19),
        'finished_at' => now()->subMinutes(9),
        'summary' => ['processed' => 9, 'errors' => 0],
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations', ['tenant' => $tenantA->slug]))
        ->assertOk()
        ->assertSee('tenant-a-run')
        ->assertDontSee('tenant-b-run');
});
