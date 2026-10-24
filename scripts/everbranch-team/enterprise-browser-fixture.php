<?php

// Synthetic, disposable development fixture only. Never reads another database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.enterprise.driver') !== 'sqlite'
    || ! str_contains(str_replace('\\', '/', config('database.connections.enterprise.database')), '/framework/testing/enterprise-browser-')) {
    throw new RuntimeException('A dedicated disposable Enterprise browser database is required.');
}
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$tenant = \App\Models\Tenant::create(['name' => 'Synthetic Enterprise', 'slug' => 'synthetic-enterprise']);
\App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
\App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
$user = \App\Models\User::forceCreate(['id' => random_int(100000, 2147483647), 'name' => 'Fixture Owner', 'email' => 'fixture-owner@example.invalid', 'password' => \Illuminate\Support\Facades\Hash::make('Synthetic-browser-only-2026!'),
    'is_active' => true, 'role' => 'member', 'email_verified_at' => now()]);
$tenant->users()->attach($user->id, ['role' => 'owner', 'membership_active' => true]);
// Additional role/tenant evidence exists only for the new disposable procedures gate.
if (config('enterprise.certificates_enabled') && config('enterprise.reports_enabled')) {
    $viewer = \App\Models\User::forceCreate(['name' => 'Fixture Procedures Viewer', 'email' => 'fixture-viewer@example.invalid',
        'password' => \Illuminate\Support\Facades\Hash::make('Synthetic-browser-only-2026!'), 'is_active' => true,
        'role' => 'member', 'email_verified_at' => now()]);
    $tenant->users()->attach($viewer->id, ['role' => 'viewer', 'membership_active' => true]);
    \App\Models\TeamArea::forceCreate(['id' => '90000000-0000-4000-8000-000000000001', 'tenant_id' => $tenant->id,
        'name' => 'Synthetic shared procedures', 'slug' => 'synthetic-shared-procedures', 'profile' => 'enterprise_shared', 'settings' => [], 'version' => 1]);
    $foreign = \App\Models\Tenant::create(['name' => 'Synthetic Foreign Enterprise', 'slug' => 'synthetic-foreign-enterprise']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $foreign->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    \App\Models\TenantModuleEntitlement::create(['tenant_id' => $foreign->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled',
        'availability_status' => 'available', 'entitlement_source' => 'test']);
    $foreignOwner = \App\Models\User::forceCreate(['name' => 'Fixture Foreign Owner', 'email' => 'fixture-foreign-owner@example.invalid',
        'password' => \Illuminate\Support\Facades\Hash::make('Synthetic-browser-only-2026!'), 'is_active' => true,
        'role' => 'member', 'email_verified_at' => now()]);
    $foreign->users()->attach($foreignOwner->id, ['role' => 'owner', 'membership_active' => true]);
    \App\Models\TeamArea::forceCreate(['id' => '90000000-0000-4000-8000-000000000002', 'tenant_id' => $foreign->id,
        'name' => 'Synthetic foreign procedures', 'slug' => 'synthetic-foreign-procedures', 'profile' => 'enterprise_department', 'settings' => [], 'version' => 1]);
    foreach ([[$tenant->id, '90000000-0000-4000-8000-000000000001', '90000000-0000-4000-8000-000000000004', 'Synthetic procedures contractor'],
        [$foreign->id, '90000000-0000-4000-8000-000000000002', '90000000-0000-4000-8000-000000000003', 'Synthetic foreign contractor']] as [$tenantId, $areaId, $id, $title]) {
        \App\Models\TeamOperationRecord::forceCreate(['id' => $id, 'tenant_id' => $tenantId, 'area_id' => $areaId,
            'kind' => 'ent_contractor', 'title' => $title, 'status' => 'active', 'version' => 1, 'visibility' => 'workspace',
            'source_key' => hash('sha256', 'procedures-fixture:'.$id), 'data' => ['contact' => '', 'skills' => [], 'notes' => 'Fictional disposable fixture only.']]);
    }
}

echo "Synthetic Enterprise browser fixture created.\n";
