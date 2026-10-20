<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$tenant = App\Models\Tenant::create(['name' => 'Fictional Premium Audit', 'slug' => 'fictional-premium-audit']);
App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'audit_synthetic']);
App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'markets_and_makers', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included']);
$access = app(App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'markets_and_makers');
$catalog = app(App\Services\Tenancy\TenantModuleCatalogService::class);
$store = $catalog->tenantStorePayload($tenant->id, 'marketing');
$public = $catalog->publicCatalogPayload();
$all = (array) config('module_catalog.modules');
echo json_encode(['environment' => app()->environment(), 'database' => config('database.connections.sqlite.database'), 'canonical_module_count' => count($all), 'canonical_module_keys' => array_keys($all), 'entitled_premium_access' => $access, 'marketplace_in_your_branches_catalog' => in_array('markets_and_makers', array_column($store['modules'], 'module_key'), true), 'marketplace_in_public_core_catalog' => in_array('markets_and_makers', array_column($public['modules'], 'key'), true), 'marketplace_in_simple_inclusions' => in_array('markets_and_makers', (array) config('module_catalog.offers.standard.included_module_keys'), true), 'premium_product' => collect($public['premium_products'])->firstWhere('key', 'markets_makers'), 'premium_internal_visibility' => config('module_catalog.modules.markets_and_makers.visibility'), 'marketplace_enabled' => config('marketplace.enabled'), 'http_sent_count' => Illuminate\Support\Facades\Http::recorded()->count()], JSON_PRETTY_PRINT).PHP_EOL;
