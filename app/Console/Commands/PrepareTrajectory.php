<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Trajectory\PilotService;
use Illuminate\Console\Command;

class PrepareTrajectory extends Command
{
    protected $signature = 'everbranch:prepare-trajectory {--owner= : Existing owner email} {--business= : Existing tenant slug, optional} {--plan= : personal, business, or both; defaults from business selection} {--enable : Enable an unbilled pilot; the global feature flag is still required}';

    protected $description = 'Prepare private household and optional linked business Trajectory spaces.';

    public function handle(PilotService $pilot): int
    {
        $owner = User::where('email', $this->option('owner'))->first();
        if (! $owner) {
            $this->error('Specify an existing owner email.');

            return self::FAILURE;
        }
        $business = $this->option('business') ? Tenant::where('slug', $this->option('business'))->first() : null;
        if ($this->option('business') && ! $business) {
            $this->error('Business workspace not found.');

            return self::FAILURE;
        }
        $spaces = $pilot->prepare($owner, $business, (bool) $this->option('enable'), $this->option('plan'));
        $this->info(count($spaces).' finance spaces prepared. No bank connected and no billing activated.');

        return self::SUCCESS;
    }
}
