<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains((string) config('database.connections.sqlite.database'), '/codex-start-here-')) {
    throw new RuntimeException('Private disposable Start Here browser SQLite only.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
$owner = App\Models\User::factory()->create(['name' => 'Fictional Printing Owner', 'email' => 'owner@start-here-fixture.invalid',
    'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!'), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
$tenants = [];
foreach (['demo' => 'Fictional Alpha Printing', 'production' => 'Fictional Printing and Community Design Workshop with a Long Workspace Name'] as $mode => $name) {
    $tenant = App\Models\Tenant::create(['name' => $name, 'slug' => 'fictional-start-here-'.$mode]);
    App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct',
        'source' => 'fictional_browser_fixture', 'metadata' => ['account_mode' => $mode]]);
    $tenant->users()->attach($owner, ['role' => 'owner', 'membership_active' => true]);
    $tenants[$mode] = ['slug' => $tenant->slug, 'name' => $name];
}
echo json_encode($tenants, JSON_THROW_ON_ERROR);
