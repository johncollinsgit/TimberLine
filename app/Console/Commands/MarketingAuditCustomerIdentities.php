<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Marketing\CustomerMergeCandidateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketingAuditCustomerIdentities extends Command
{
    protected $signature = 'marketing:audit-customer-identities
        {--tenant=modern-forestry : Tenant id or slug}
        {--store=retail : Shopify store key used for identity evidence}
        {--query= : Optional customer name or email filter}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Preview duplicate customer identities and stranded Candle Cash without changing data.';

    public function handle(CustomerMergeCandidateService $candidates): int
    {
        $tenantValue = trim((string) $this->option('tenant'));
        $tenant = ctype_digit($tenantValue)
            ? Tenant::query()->find((int) $tenantValue)
            : Tenant::query()->where('slug', $tenantValue)->first();
        if (! $tenant) {
            $this->error('The requested tenant could not be resolved.');

            return self::FAILURE;
        }

        $filter = strtolower(trim((string) $this->option('query')));
        $profiles = DB::table('marketing_profiles as profiles')
            ->where('profiles.tenant_id', $tenant->id)
            ->whereNull('profiles.merged_at')
            ->get(['profiles.id', 'profiles.first_name', 'profiles.last_name', 'profiles.email', 'profiles.normalized_email', 'profiles.normalized_phone']);
        if ($filter !== '') {
            $profiles = $profiles->filter(function (object $profile) use ($filter): bool {
                $haystack = strtolower(trim($profile->first_name.' '.$profile->last_name.' '.$profile->email));

                return str_contains($haystack, $filter);
            })->values();
        }

        $duplicateKeys = $profiles->flatMap(function (object $profile): array {
            return array_values(array_filter([
                trim((string) $profile->normalized_email) !== '' ? 'email:'.strtolower((string) $profile->normalized_email) : null,
                trim((string) $profile->normalized_phone) !== '' ? 'phone:'.(string) $profile->normalized_phone : null,
            ]));
        })->countBy()->filter(fn (int $count): bool => $count > 1)->keys();

        $clusters = $duplicateKeys->map(function (string $key) use ($profiles): array {
            [$type, $value] = explode(':', $key, 2);
            $members = $profiles->filter(fn (object $profile): bool => (string) $profile->{'normalized_'.$type} === $value);

            return [
                'identity' => $key,
                'profiles' => $members->map(function (object $profile) use ($members): array {
                    $balance = (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->value('balance');
                    $ledger = (float) DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id)->sum('candle_cash_delta');

                    return [
                        'id' => (int) $profile->id,
                        'name' => trim($profile->first_name.' '.$profile->last_name),
                        'email' => $profile->email,
                        'shopify_ids' => DB::table('customer_external_profiles')
                            ->where('marketing_profile_id', $profile->id)
                            ->where('provider', 'shopify')
                            ->pluck('external_customer_gid')
                            ->merge(DB::table('marketing_profile_links')
                                ->where('marketing_profile_id', $profile->id)
                                ->whereIn('source_type', ['shopify_customer', 'growave_customer'])
                                ->pluck('source_id'))
                            ->filter()
                            ->unique()
                            ->values()
                            ->all(),
                        'balance' => $balance,
                        'ledger_net' => $ledger,
                        'stranded_or_mismatched_rewards' => abs($balance - $ledger) >= 0.005 || ($balance !== 0.0 && $members->count() > 1),
                    ];
                })->values()->all(),
            ];
        })->values();

        $payload = [
            'mode' => 'preview',
            'tenant_id' => (int) $tenant->id,
            'tenant_slug' => (string) $tenant->slug,
            'clusters' => $clusters->count(),
            'results' => $clusters->all(),
            'search_candidates' => $filter !== ''
                ? $candidates->search((int) $tenant->id, (string) $this->option('query'), trim((string) $this->option('store')) ?: 'retail', 50)
                : [],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Preview only: {$payload['clusters']} duplicate identity clusters found.");
            foreach ($clusters as $cluster) {
                $this->line((string) $cluster['identity']);
                foreach ($cluster['profiles'] as $profile) {
                    $this->line(sprintf('  profile=%d name=%s balance=%.3f ledger=%.3f review=%s', $profile['id'], $profile['name'], $profile['balance'], $profile['ledger_net'], $profile['stranded_or_mismatched_rewards'] ? 'yes' : 'no'));
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
