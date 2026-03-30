<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingTenantOwnershipService;
use Illuminate\Console\Command;

class MarketingGenerateRecommendations extends Command
{
    protected $signature = 'marketing:generate-recommendations
        {--campaign-id= : Generate for one campaign ID instead of global}
        {--tenant-id= : Restrict generation to a tenant-owned campaign/report surface}
        {--dry-run : Evaluate rules without writing recommendations}
        {--show-details : Print expanded run result}';

    protected $description = 'Generate rule-based marketing recommendations using performance, timing, and event/reward signals.';

    public function handle(
        MarketingRecommendationEngine $engine,
        MarketingTenantOwnershipService $ownershipService
    ): int
    {
        $campaignId = $this->option('campaign-id');
        $tenantId = is_numeric($this->option('tenant-id')) ? max(1, (int) $this->option('tenant-id')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $showDetails = (bool) $this->option('show-details');
        $strict = $ownershipService->strictModeEnabled();

        if ($strict && $tenantId === null) {
            $this->error('This command requires --tenant-id once tenant strict mode is active.');

            return self::FAILURE;
        }

        if (is_numeric($campaignId) && (int) $campaignId > 0) {
            $campaign = MarketingCampaign::query()->find((int) $campaignId);
            if (! $campaign) {
                $this->error('Campaign not found for --campaign-id=' . $campaignId);

                return self::FAILURE;
            }

            if ($tenantId !== null && ! $ownershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId)) {
                $this->error(sprintf(
                    'Campaign %d is outside tenant %d ownership scope.',
                    (int) $campaign->id,
                    $tenantId
                ));

                return self::FAILURE;
            }

            $result = $engine->generateForCampaign($campaign, [
                'dry_run' => $dryRun,
                'tenant_id' => $tenantId,
            ]);
            $this->info(sprintf(
                'Campaign recommendation run complete. campaign_id=%d created=%d potential=%d',
                (int) $campaign->id,
                (int) ($result['created'] ?? 0),
                (int) ($result['potential'] ?? 0)
            ));
        } else {
            $result = $engine->generateGlobal([
                'dry_run' => $dryRun,
                'tenant_id' => $tenantId,
            ]);
            $this->info(sprintf(
                'Recommendation run complete. created=%d potential=%d',
                (int) ($result['created'] ?? 0),
                (int) ($result['potential'] ?? 0)
            ));
        }

        if ($showDetails) {
            $this->line(json_encode($result));
        }

        if ($dryRun) {
            $this->comment('Dry run mode enabled: recommendation rows were not persisted.');
        }

        return self::SUCCESS;
    }
}
