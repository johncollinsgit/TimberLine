<?php

namespace App\Console\Commands;

use App\Models\CandleCashRedemption;
use App\Models\MarketingStorefrontEvent;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use Illuminate\Console\Command;

class MarketingScanUnresolvedMarketingIssues extends Command
{
    protected $signature = 'marketing:scan-unresolved-marketing-issues
        {--tenant-id= : Restrict execution to a tenant id (required)}
        {--limit=2000 : Max issued redemptions to scan}
        {--platform=all : all|shopify|square}
        {--dry-run : Evaluate without writing issue rows}
        {--show-rows : Print per-row detail}';

    protected $description = 'Scan and materialize unresolved marketing reconciliation issues for operational dashboard review.';

    public function handle(MarketingStorefrontEventLogger $logger): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        if ($tenantId === null || $tenantId <= 0) {
            $this->error('Missing required --tenant-id. Unresolved issue scan is tenant-scoped in MT-2C.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $platform = strtolower(trim((string) $this->option('platform')));
        if (! in_array($platform, ['all', 'shopify', 'square'], true)) {
            $this->error('Invalid --platform. Use all|shopify|square.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $showRows = (bool) $this->option('show-rows');

        $issued = CandleCashRedemption::query()
            ->where('status', 'issued')
            ->whereHas('profile', fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($platform !== 'all', fn ($query) => $query->where('platform', $platform))
            ->orderBy('issued_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'marketing_profile_id', 'platform', 'redemption_code', 'issued_at']);

        $summary = [
            'issued_scanned' => 0,
            'pending_created_or_updated' => 0,
            'open_events' => 0,
            'resolved_events' => 0,
        ];

        foreach ($issued as $row) {
            $summary['issued_scanned']++;

            $dedupeKey = sha1('issued_pending|' . $row->id);
            if (! $dryRun) {
                $logger->log('redemption_reconciliation_pending', [
                    'status' => 'pending',
                    'issue_type' => 'issued_not_reconciled',
                    'source_surface' => 'ingestion',
                    'endpoint' => 'reward_reconciliation_scan',
                    'tenant_id' => $tenantId,
                    'marketing_profile_id' => (int) $row->marketing_profile_id,
                    'candle_cash_redemption_id' => (int) $row->id,
                    'source_type' => 'candle_cash_redemption',
                    'source_id' => (string) $row->id,
                    'dedupe_key' => $dedupeKey,
                    'meta' => [
                        'platform' => (string) ($row->platform ?: 'unknown'),
                        'redemption_code' => (string) $row->redemption_code,
                        'issued_at' => optional($row->issued_at)->toIso8601String(),
                    ],
                    'resolution_status' => 'open',
                ]);
            }

            $summary['pending_created_or_updated']++;
            if ($showRows) {
                $this->line('pending_issue: redemption_id=' . $row->id . ' platform=' . ($row->platform ?: 'n/a'));
            }
        }

        $summary['open_events'] = (int) MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('resolution_status', 'open')
            ->whereIn('status', ['error', 'verification_required', 'pending'])
            ->count();
        $summary['resolved_events'] = (int) MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('resolution_status', 'resolved')
            ->count();

        foreach ($summary as $key => $value) {
            $this->line($key . '=' . (int) $value);
        }
        $this->line('tenant_id=' . $tenantId);
        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));

        return self::SUCCESS;
    }
}
