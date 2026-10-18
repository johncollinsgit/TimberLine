<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AiBranch\AiBranchPurchase;
use Illuminate\Console\Command;

class AiBranchPreparePurchase extends Command
{
    protected $signature = 'ai-branch:prepare-purchase {tenant : Exact workspace slug} {--actor= : Active platform operator user ID} {--authorization= : Exact human approval reference, without secrets} {--apply : Create or resume the approved hosted checkout; never pay it}';

    protected $description = 'Preview or prepare one private AI Growth purchase for an allowlisted workspace';

    public function handle(AiBranchPurchase $purchase): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->firstOrFail();
        $operator = User::query()->findOrFail((int) $this->option('actor'));
        $result = $this->option('apply') ? $purchase->prepare($tenant, $operator, trim((string) $this->option('authorization'))) : $purchase->preview($tenant, $operator);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! $this->option('apply')) {
            $this->line('Preview only. No Stripe call, charge or entitlement change.');
        }

        return self::SUCCESS;
    }
}
