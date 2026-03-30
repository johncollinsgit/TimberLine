<?php

namespace App\Console\Commands;

use App\Models\CandleCashRedemption;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use Illuminate\Console\Command;

class MarketingRepairStorefrontLinks extends Command
{
    protected $signature = 'marketing:repair-storefront-links
        {--tenant-id= : Restrict execution to a tenant id (required)}
        {--limit=1000 : Max storefront events to scan}
        {--dry-run : Evaluate without writing updates}
        {--show-actions : Print per-event actions}';

    protected $description = 'Repair unresolved storefront/public events by backfilling missing profile links where safe.';

    public function handle(): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        if ($tenantId === null || $tenantId <= 0) {
            $this->error('Missing required --tenant-id. Storefront link repair is tenant-scoped in MT-2C.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $showActions = (bool) $this->option('show-actions');

        $events = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->whereNull('marketing_profile_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $summary = [
            'scanned' => 0,
            'linked_from_source_link' => 0,
            'linked_from_redemption' => 0,
            'skipped' => 0,
        ];

        foreach ($events as $event) {
            $summary['scanned']++;

            $profileId = null;
            if ($event->source_type && $event->source_id) {
                $profileId = (int) (MarketingProfileLink::query()
                    ->forTenantId($tenantId)
                    ->where('source_type', $event->source_type)
                    ->where('source_id', $event->source_id)
                    ->value('marketing_profile_id') ?? 0);
                if ($profileId > 0) {
                    $summary['linked_from_source_link']++;
                    if ($showActions) {
                        $this->line('link: event_id=' . $event->id . ' via source_link profile_id=' . $profileId);
                    }
                }
            }

            if (! $profileId && $event->candle_cash_redemption_id) {
                $profileId = (int) (CandleCashRedemption::query()
                    ->whereKey($event->candle_cash_redemption_id)
                    ->whereHas('profile', fn ($query) => $query->where('tenant_id', $tenantId))
                    ->value('marketing_profile_id') ?? 0);
                if ($profileId > 0) {
                    $summary['linked_from_redemption']++;
                    if ($showActions) {
                        $this->line('link: event_id=' . $event->id . ' via redemption profile_id=' . $profileId);
                    }
                }
            }

            if ($profileId > 0) {
                if (! $dryRun) {
                    $event->forceFill([
                        'marketing_profile_id' => $profileId,
                    ])->save();
                }
            } else {
                $summary['skipped']++;
            }
        }

        foreach ($summary as $key => $value) {
            $this->line($key . '=' . (int) $value);
        }
        $this->line('tenant_id=' . $tenantId);
        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));

        return self::SUCCESS;
    }
}
