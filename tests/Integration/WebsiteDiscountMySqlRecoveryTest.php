<?php

use App\Models\Tenant;
use App\Models\TenantSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('recovers retained discount columns and records before MySQL index and foreign-key DDL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_10_20_020000_create_website_discounts.php');
    $migration->up();
    $tenant = Tenant::create(['name' => 'Fictional discount recovery', 'slug' => 'discount-test-'.Str::lower(Str::random(12))]);
    $site = TenantSite::create(['tenant_id' => $tenant->id, 'subdomain' => $tenant->slug, 'status' => 'draft', 'settings' => []]);
    $code = 'RECOVERY_'.strtoupper(Str::random(16));
    DB::table('website_discounts')->insert(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'code' => $code, 'type' => 'fixed', 'value' => 1234, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['wd_tenant_fk', 'wd_site_fk'] as $name) {
        Schema::table('website_discounts', fn ($table) => $table->dropForeign($name));
    }
    foreach (['wd_site_code_uq', 'wd_site_idx'] as $name) {
        Schema::table('website_discounts', fn ($table) => $table->dropIndex($name));
    }
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('website_discounts', 'wd_site_code_uq'))->toBeTrue()
        ->and(Schema::hasIndex('website_discounts', 'wd_site_idx'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('website_discounts'))->pluck('name')->all())->toContain('wd_tenant_fk', 'wd_site_fk')
        ->and(DB::table('website_discounts')->where('code', $code)->value('value'))->toBe(1234);
    $tenant->delete();
});
