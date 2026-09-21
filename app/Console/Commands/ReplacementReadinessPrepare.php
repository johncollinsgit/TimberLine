<?php

namespace App\Console\Commands;

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Replacements\OmniumLocatorImportService;
use App\Services\Replacements\ReplacementReadinessService;
use App\Services\Replacements\StoreifyFormImportService;
use Illuminate\Console\Command;

class ReplacementReadinessPrepare extends Command
{
    protected $signature = 'everbranch:replacement-readiness:prepare
        {--tenant=modern-forestry : Tenant slug}
        {--store=retail : Shopify store key}
        {--source-dir= : Directory containing Storeify XLSX and Omnium JSON exports}
        {--module=all : all, storeify_forms, omnium_locator, or catalog}
        {--verify-only : Validate source files without writing records}';

    protected $description = 'Prepare inactive replacement modules and import audited Storeify and Omnium source exports.';

    public function handle(
        ReplacementReadinessService $readiness,
        StoreifyFormImportService $storeify,
        OmniumLocatorImportService $omnium
    ): int {
        $sourceDir = (string) ($this->option('source-dir') ?: base_path('../output/shopify-app-audit-2026-09-21'));
        $module = strtolower(trim((string) $this->option('module')));
        if (! in_array($module, ['all', 'catalog', 'storeify_forms', 'omnium_locator'], true)) {
            $this->error('Unsupported --module value.');

            return self::FAILURE;
        }

        $requiredFiles = [];
        if (in_array($module, ['all', 'storeify_forms'], true)) {
            foreach (['contact-us-5367.xlsx', 'forestry-weekend-16798.xlsx', 'job-opportunity-5426.xlsx', 'wholesale-application-5368.xlsx'] as $name) {
                $requiredFiles[] = rtrim($sourceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'storeify-exports'.DIRECTORY_SEPARATOR.$name;
            }
        }
        if (in_array($module, ['all', 'omnium_locator'], true)) {
            $requiredFiles[] = rtrim($sourceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'omnium-full_data.json';
        }
        foreach ($requiredFiles as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error('Missing or unreadable source: '.$file);

                return self::FAILURE;
            }
        }
        if ((bool) $this->option('verify-only')) {
            $this->info('Source package is readable. No records changed.');
            foreach ($requiredFiles as $file) {
                $this->line(hash_file('sha256', $file).'  '.basename($file));
            }

            return self::SUCCESS;
        }

        $tenant = Tenant::query()->where('slug', strtolower(trim((string) $this->option('tenant'))))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $store = ShopifyStore::query()->forTenantId((int) $tenant->id)->where('store_key', strtolower(trim((string) $this->option('store'))))->first();
        if (! $store) {
            $this->error('Shopify store not found for tenant.');

            return self::FAILURE;
        }

        $readiness->ensureCatalog((int) $tenant->id);
        $this->info('Replacement readiness catalog prepared.');
        if (in_array($module, ['all', 'storeify_forms'], true)) {
            $result = $storeify->import($tenant, $store, $sourceDir.DIRECTORY_SEPARATOR.'storeify-exports');
            $this->line('storeify_forms='.json_encode($result, JSON_THROW_ON_ERROR));
        }
        if (in_array($module, ['all', 'omnium_locator'], true)) {
            $result = $omnium->import($tenant, $store, $sourceDir.DIRECTORY_SEPARATOR.'omnium-full_data.json');
            $this->line('omnium_locator='.json_encode($result, JSON_THROW_ON_ERROR));
        }
        $this->warn('All imported candidates remain inactive. No storefront, checkout, shipping, or subscription provider changed.');

        return self::SUCCESS;
    }
}
