<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Console\Command;

class PrepareRetailShowroom extends Command
{
    protected $signature = 'everbranch:prepare-retail-showroom {--owner-email=} {--dry-run}';

    protected $description = 'Prepare seven isolated Woolly Worm retail concept drafts without merchant activation or provider actions.';

    public function handle(RetailShowroomService $showroom): int
    {
        $owner = User::where('email', $this->option('owner-email') ?: config('retail_showroom.owner_email'))->first();
        if (! $showroom->owner($owner)) {
            $this->error('Configure an active verified showroom owner and enable the private showroom first.');

            return self::FAILURE;
        }
        $names = array_column($showroom->definitions()['vendors'], 'name');
        if ($this->option('dry-run')) {
            $this->info('Dry run: '.implode(', ', $names));

            return self::SUCCESS;
        }
        $showroom->seed($owner);
        $this->info('Prepared seven private demo sites, prospect records, and draft blueprints. No merchant account, payments, shipping or public website activated.');

        return self::SUCCESS;
    }
}
