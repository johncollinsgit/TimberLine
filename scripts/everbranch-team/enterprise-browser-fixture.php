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
$user = \App\Models\User::forceCreate(['name' => 'Fixture Owner', 'email' => 'fixture-owner@example.invalid', 'password' => \Illuminate\Support\Facades\Hash::make('Synthetic-browser-only-2026!'),
    'is_active' => true, 'role' => 'member', 'email_verified_at' => now()]);
$tenant->users()->attach($user->id, ['role' => 'owner', 'membership_active' => true]);
echo "Synthetic Enterprise browser fixture created.\n";
