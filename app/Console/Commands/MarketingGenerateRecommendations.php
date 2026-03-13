<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingRecommendationEngine;
use Illuminate\Console\Command;

class MarketingGenerateRecommendations extends Command
{
    protected $signature = 'marketing:generate-recommendations
        {--campaign-id= : Generate for one campaign ID instead of global}
        {--dry-run : Evaluate rules without writing recommendations}
        {--verbose : Print expanded run result}';

    protected $description = 'Generate rule-based marketing recommendations using performance, timing, and event/reward signals.';

    public function handle(MarketingRecommendationEngine $engine): int
    {
        $campaignId = $this->option('campaign-id');
        $dryRun = (bool) $this->option('dry-run');
        $verbose = (bool) $this->option('verbose');

        if (is_numeric($campaignId) && (int) $campaignId > 0) {
            $campaign = MarketingCampaign::query()->find((int) $campaignId);
            if (! $campaign) {
                $this->error('Campaign not found for --campaign-id=' . $campaignId);

                return self::FAILURE;
            }

            $result = $engine->generateForCampaign($campaign, ['dry_run' => $dryRun]);
            $this->info(sprintf(
                'Campaign recommendation run complete. campaign_id=%d created=%d potential=%d',
                (int) $campaign->id,
                (int) ($result['created'] ?? 0),
                (int) ($result['potential'] ?? 0)
            ));
        } else {
            $result = $engine->generateGlobal(['dry_run' => $dryRun]);
            $this->info(sprintf(
                'Global recommendation run complete. created=%d potential=%d',
                (int) ($result['created'] ?? 0),
                (int) ($result['potential'] ?? 0)
            ));
        }

        if ($verbose) {
            $this->line(json_encode($result));
        }

        if ($dryRun) {
            $this->comment('Dry run mode enabled: recommendation rows were not persisted.');
        }

        return self::SUCCESS;
    }
}

