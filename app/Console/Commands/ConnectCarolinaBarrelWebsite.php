<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConnectCarolinaBarrelWebsite extends Command
{
    protected $signature = 'website:connect-carolina-barrel {--actor= : Existing authorized user ID} {--apply : Commit the reviewed live baseline}';

    protected $description = 'Import the current Carolina Barrel live renderer into versioned Website content (dry run by default).';

    public function handle(ConnectedWebsiteService $service): int
    {
        $tenant = Tenant::query()->where('slug', ConnectedWebsiteService::SLUG)->firstOrFail();
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $actor = User::query()->findOrFail((int) $this->option('actor'));
        if ($service->connected($site)) {
            $this->info('Already connected; no changes.');

            return self::SUCCESS;
        }
        DB::beginTransaction();
        try {
            $service->connect($site, $actor);
            $this->info('Validated existing live content, five quote products, immutable snapshots, and editor access. No messages or billing changes.');
            if ($this->option('apply')) {
                DB::commit();
                $this->info('Connected.');
            } else {
                DB::rollBack();
                $this->info('Dry run rolled back.');
            }
        } catch (\Throwable $error) {
            DB::rollBack();
            throw $error;
        }

        return self::SUCCESS;
    }
}
