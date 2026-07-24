<?php

namespace App\Console\Commands;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Services\Automation\V2\LegacyV2WorkflowPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AutomationPromoteLegacyWorkflowV2 extends Command
{
    protected $signature = 'automation:promote-legacy-v2
        {tenant : Tenant id or slug}
        {--shadow : Run and record one read-only v1/v2 parity comparison}
        {--confirm : Confirm parity, atomically publish v2, and remap links}';

    protected $description = 'Promote an active schema-v1 Asana→Google Calendar workflow after three matching v2 shadows.';

    public function handle(LegacyV2WorkflowPromotionService $promotion): int
    {
        $shadow = (bool) $this->option('shadow');
        $confirm = (bool) $this->option('confirm');
        if ($shadow === $confirm) {
            $this->error('Choose exactly one mode: --shadow or --confirm.');

            return self::FAILURE;
        }

        $tenantValue = (string) $this->argument('tenant');
        $tenant = Tenant::query()
            ->where('slug', $tenantValue)
            ->orWhere('id', ctype_digit($tenantValue) ? (int) $tenantValue : 0)
            ->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $workflow = AutomationWorkflow::query()
            ->forAllTenants()
            ->where('tenant_id', $tenant->id)
            ->where('template_key', 'asana_to_google_calendar')
            ->where('status', AutomationWorkflow::STATUS_ACTIVE)
            ->with('publishedVersion')
            ->first();
        if (! $workflow) {
            $this->error('No active legacy Asana to Google Calendar workflow was found.');

            return self::FAILURE;
        }

        // RunAutomationWorkflowJob uses this shared key. Holding it keeps the
        // v1 cursor stable across both provider reads and final publication.
        $lock = Cache::lock(
            'laravel-queue-overlap:automation-workflow:'.$workflow->id,
            900,
        );
        if (! $lock->get()) {
            $this->error('The workflow is currently running. Try again after it finishes.');

            return self::FAILURE;
        }

        try {
            if ($shadow) {
                $result = $promotion->qualify($workflow);
                $this->line('legacy_run_id='.$result['legacy_run_id']);
                $this->line('v2_shadow_run_id='.$result['shadow_run_id']);
                $this->line('v2_shadow_parity_streak='.min(3, (int) $result['streak']).'/3');
                $this->info((bool) $result['qualified']
                    ? 'Shadow matched. The v2 promotion gate is ready for --confirm.'
                    : 'Shadow matched. Repeat --shadow until three consecutive comparisons qualify.');

                return self::SUCCESS;
            }

            $gate = $promotion->gate($workflow);
            if ($gate['count'] < 3) {
                $this->error(sprintf(
                    'V2 promotion blocked: %d of 3 consecutive matching shadows are recorded.',
                    $gate['count'],
                ));

                return self::FAILURE;
            }
            $promoted = $promotion->promote($workflow);
            $this->info(sprintf(
                'V2 promotion completed. Published version %d; schema-v1 version remains immutable for rollback.',
                (int) $promoted->publishedVersion?->version,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
