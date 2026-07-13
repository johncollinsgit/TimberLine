<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Marketing\CustomerIdentityAuditService;
use App\Services\Marketing\CustomerMergeCandidateService;
use Illuminate\Console\Command;

class MarketingAuditCustomerIdentities extends Command
{
    protected $signature = 'marketing:audit-customer-identities
        {--tenant=modern-forestry : Tenant id or slug}
        {--store=retail : Shopify store key used for identity evidence}
        {--query= : Optional customer name or email filter}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Preview duplicate customer identities and stranded Candle Cash without changing data.';

    public function handle(CustomerIdentityAuditService $audit, CustomerMergeCandidateService $candidates): int
    {
        $tenantValue = trim((string) $this->option('tenant'));
        $tenant = ctype_digit($tenantValue)
            ? Tenant::query()->find((int) $tenantValue)
            : Tenant::query()->where('slug', $tenantValue)->first();
        if (! $tenant) {
            $this->error('The requested tenant could not be resolved.');

            return self::FAILURE;
        }

        $storeKey = trim((string) $this->option('store')) ?: 'retail';
        $query = trim((string) $this->option('query'));
        $payload = $audit->audit((int) $tenant->id, (string) $tenant->slug, $storeKey, $query ?: null);
        $payload['search_candidates'] = $query !== ''
                ? $candidates->search((int) $tenant->id, (string) $this->option('query'), trim((string) $this->option('store')) ?: 'retail', 50)
                : [];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Preview only: {$payload['clusters']} duplicate identity clusters found.");
            foreach ($payload['results'] as $cluster) {
                $this->line(implode(', ', $cluster['identities']));
                $this->line(sprintf(
                    '  status=%s survivor=%s shopify_merge=%s reasons=%s',
                    $cluster['status'],
                    $cluster['recommended_survivor_profile_id'] ?: 'none',
                    $cluster['shopify_merge_required'] ? 'yes' : 'no',
                    $cluster['review_reasons'] === [] ? 'none' : implode('|', $cluster['review_reasons']),
                ));
                foreach ($cluster['profiles'] as $profile) {
                    $this->line(sprintf('  profile=%d name=%s balance=%.3f ledger=%.3f transactions=%d birthdays=%d owned_records=%d', $profile['id'], $profile['name'], $profile['balance'], $profile['ledger_net'], $profile['transaction_count'], count($profile['birthdays']), array_sum($profile['owned_record_counts'])));
                }
            }
            if ($payload['search_candidates'] !== []) {
                $this->line('search_candidates='.count($payload['search_candidates']));
                foreach ($payload['search_candidates'] as $candidate) {
                    $this->line(sprintf(
                        '  profile=%d name=%s shopify=%s balance=%.3f ledger_entries=%d',
                        (int) $candidate['id'],
                        (string) $candidate['name'],
                        (string) ($candidate['shopify_customer_gid'] ?? 'none'),
                        (float) $candidate['candle_cash_balance'],
                        (int) $candidate['candle_cash_transactions'],
                    ));
                }
            }
        }

        return self::SUCCESS;
    }
}
