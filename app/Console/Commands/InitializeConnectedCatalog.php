<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeConnectedCatalog extends Command
{
    protected $signature = 'website:initialize-connected-catalog {--actor=} {--apply}';

    protected $description = 'Backfill the existing Carolina Barrel product details and collections; dry run by default.';

    public function handle(ConnectedWebsiteService $service): int
    {
        $tenant = Tenant::query()->where('slug', ConnectedWebsiteService::SLUG)->firstOrFail();
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $actor = User::query()->findOrFail((int) $this->option('actor'));
        DB::beginTransaction();
        try {
            $service->initializeCatalog($site, $actor);
            $this->info('Existing product details and collection membership validated. No products, messages, orders or payments created.');
            if ($this->option('apply')) {
                DB::commit();
                $this->info('Catalog initialized.');
            } else {
                DB::rollBack();
                $this->info('Dry run rolled back.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return self::SUCCESS;
    }
}
