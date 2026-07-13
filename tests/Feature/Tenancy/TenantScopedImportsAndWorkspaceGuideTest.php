<?php

use App\Models\MappingException;
use App\Models\ShopifyImportRun;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

function tenantWithAccess(string $name, string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    return $tenant;
}

test('shopify import attention and run history stay inside the active tenant', function () {
    $modernForestry = tenantWithAccess('Modern Forestry', 'modern-forestry');
    $collins = tenantWithAccess('Collins Electric', 'collins-electric');
    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($modernForestry->id, ['role' => 'owner']);
    $user->tenants()->attach($collins->id, ['role' => 'admin']);

    MappingException::query()->create([
        'tenant_id' => $modernForestry->id,
        'store_key' => 'wholesale',
        'raw_title' => 'Modern Forestry custom scent',
        'reason' => 'unmapped_scent',
    ]);

    $run = ShopifyImportRun::query()->create([
        'tenant_id' => $modernForestry->id,
        'store_key' => 'wholesale',
        'source' => 'shopify_wholesale',
        'mapping_exceptions_count' => 1,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $collins->id])
        ->get('/wiki')
        ->assertOk()
        ->assertDontSeeText('Import Attention')
        ->assertDontSeeText('Modern Forestry custom scent');

    $this->actingAs($user)
        ->withSession(['tenant_id' => $collins->id])
        ->get('/admin?tab=imports')
        ->assertOk()
        ->assertSeeText('No import runs yet.')
        ->assertDontSeeText('#'.$run->id);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $modernForestry->id])
        ->get('/wiki')
        ->assertOk()
        ->assertSeeText('Import Attention')
        ->assertSeeText('1 unmapped line item need review.')
        ->assertSeeText('Last run: #'.$run->id.' (wholesale)');
});

test('each non-flagship tenant receives its own workspace guide instead of the Modern Forestry wiki', function () {
    $modernForestry = tenantWithAccess('Modern Forestry', 'modern-forestry');
    $collins = tenantWithAccess('Collins Electric', 'collins-electric');
    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($modernForestry->id, ['role' => 'owner']);
    $user->tenants()->attach($collins->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $collins->id])
        ->get('/wiki')
        ->assertOk()
        ->assertSeeText('Collins Electric')
        ->assertSeeText('Customers')
        ->assertDontSeeText('Wholesale Processes')
        ->assertDontSeeText('Market Room Process');

    $this->actingAs($user)
        ->withSession(['tenant_id' => $collins->id])
        ->get('/wiki/article/quickbooks-connection')
        ->assertOk()
        ->assertSeeText('QuickBooks Connection')
        ->assertSeeText('tenant-scoped');

    $this->actingAs($user)
        ->withSession(['tenant_id' => $collins->id])
        ->get('/wiki/article/wholesale-overview')
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['tenant_id' => $modernForestry->id])
        ->get('/wiki')
        ->assertOk()
        ->assertSeeText('Wholesale Processes')
        ->assertSeeText('Market Room Process')
        ->assertDontSee('/wiki/article/quickbooks-connection', false);
});
