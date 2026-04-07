<?php

namespace App\Console\Commands;

use App\Services\Marketing\LegacyGrowaveCandleCashRehomeService;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Console\Command;

class MarketingRehomeLegacyGrowaveCandleCash extends Command
{
    protected $signature = 'marketing:rehome-legacy-growave-candle-cash
        {--tenant-id=1 : Target tenant id for canonical profile links}
        {--store=retail : Shopify store key prefix to match source ids (e.g. retail)}
        {--include-wholesale : Include profiles with wholesale evidence (disabled by default)}
        {--profile-id= : Restrict to one legacy old marketing profile id}
        {--chunk=500 : Chunk size for apply updates}
        {--sample=15 : Number of eligible mapping samples to print}
        {--apply : Persist profile id rehome updates}';

    protected $description = 'Preview/apply deterministic rehome of legacy Growave Candle Cash from null-tenant duplicate profiles to canonical tenant profiles.';

    public function handle(LegacyGrowaveCandleCashRehomeService $service): int
    {
        $tenantId = $this->positiveInt($this->option('tenant-id')) ?? 1;
        $store = $this->normalizedStore($this->option('store')) ?? 'retail';
        $includeWholesale = (bool) $this->option('include-wholesale');
        $profileId = $this->positiveInt($this->option('profile-id'));
        $chunk = max(1, (int) ($this->positiveInt($this->option('chunk')) ?? 500));
        $sample = max(0, min(100, (int) ($this->positiveInt($this->option('sample')) ?? 15)));
        $apply = (bool) $this->option('apply');

        $result = $service->run([
            'tenant_id' => $tenantId,
            'store' => $store,
            'include_wholesale' => $includeWholesale,
            'profile_id' => $profileId,
            'chunk' => $chunk,
            'sample' => $sample,
            'apply' => $apply,
        ]);

        $this->line('mode=' . (string) ($result['mode'] ?? ($apply ? 'apply' : 'preview')));
        $this->line('tenant_id=' . (string) ($result['tenant_id'] ?? $tenantId));
        $this->line('store=' . (string) ($result['store'] ?? $store));
        $this->line('include_wholesale=' . ((bool) ($result['include_wholesale'] ?? $includeWholesale) ? 'yes' : 'no'));
        $this->line('profile_id=' . ((int) ($result['profile_id'] ?? 0) > 0 ? (string) $result['profile_id'] : 'all'));
        $this->line('chunk=' . (string) ($result['chunk'] ?? $chunk));

        $this->line('raw_pair_rows=' . (int) ($result['raw_pair_rows'] ?? 0));
        $this->line('candidate_pairs=' . (int) ($result['candidate_pairs'] ?? 0));
        $this->line('candidate_old_profiles=' . (int) ($result['candidate_old_profiles'] ?? 0));
        $this->line('candidate_target_profiles=' . (int) ($result['candidate_target_profiles'] ?? 0));
        $this->line('excluded_wholesale_profiles=' . (int) ($result['excluded_wholesale_profiles'] ?? 0));
        $this->line('pairs_after_wholesale=' . (int) ($result['pairs_after_wholesale'] ?? 0));
        $this->line('ambiguous_old_profiles=' . (int) ($result['ambiguous_old_profiles'] ?? 0));
        $this->line('ambiguous_target_profiles=' . (int) ($result['ambiguous_target_profiles'] ?? 0));
        $this->line('eligible_pairs=' . (int) ($result['eligible_pairs'] ?? 0));
        $this->line('eligible_old_profiles=' . (int) ($result['eligible_old_profiles'] ?? 0));
        $this->line('eligible_target_profiles=' . (int) ($result['eligible_target_profiles'] ?? 0));

        $pre = is_array($result['pre'] ?? null) ? $result['pre'] : [];
        $this->line('pre_old_balance_sum=' . $this->formatAmount($pre['old_balance_sum'] ?? 0));
        $this->line('pre_target_balance_sum=' . $this->formatAmount($pre['target_balance_sum'] ?? 0));
        $this->line('pre_old_ledger_sum=' . $this->formatAmount($pre['old_ledger_sum'] ?? 0));
        $this->line('pre_target_ledger_sum=' . $this->formatAmount($pre['target_ledger_sum'] ?? 0));

        $rowsToMove = is_array($result['rows_to_move'] ?? null) ? $result['rows_to_move'] : [];
        $this->line('rows_to_move_transactions=' . (int) ($rowsToMove['transactions'] ?? 0));
        $this->line('rows_to_move_redemptions=' . (int) ($rowsToMove['redemptions'] ?? 0));
        $this->line('rows_to_move_task_completions=' . (int) ($rowsToMove['task_completions'] ?? 0));
        $this->line('rows_to_move_task_events=' . (int) ($rowsToMove['task_events'] ?? 0));
        $this->line('rows_to_move_referrals_referrer=' . (int) ($rowsToMove['referrals_referrer'] ?? 0));
        $this->line('rows_to_move_referrals_referred=' . (int) ($rowsToMove['referrals_referred'] ?? 0));

        $applied = is_array($result['applied'] ?? null) ? $result['applied'] : [];
        $moved = is_array($applied['rows_moved'] ?? null) ? $applied['rows_moved'] : [];
        $this->line('rows_moved_transactions=' . (int) ($moved['transactions'] ?? 0));
        $this->line('rows_moved_redemptions=' . (int) ($moved['redemptions'] ?? 0));
        $this->line('rows_moved_task_completions=' . (int) ($moved['task_completions'] ?? 0));
        $this->line('rows_moved_task_events=' . (int) ($moved['task_events'] ?? 0));
        $this->line('rows_moved_referrals_referrer=' . (int) ($moved['referrals_referrer'] ?? 0));
        $this->line('rows_moved_referrals_referred=' . (int) ($moved['referrals_referred'] ?? 0));
        $this->line('balance_rows_deleted=' . (int) ($applied['balance_rows_deleted'] ?? 0));
        $this->line('balance_rows_inserted=' . (int) ($applied['balance_rows_inserted'] ?? 0));
        $this->line('balance_rows_updated=' . (int) ($applied['balance_rows_updated'] ?? 0));
        $this->line('balance_rows_unchanged=' . (int) ($applied['balance_rows_unchanged'] ?? 0));

        $post = is_array($result['post'] ?? null) ? $result['post'] : [];
        $this->line('post_old_balance_sum=' . $this->formatAmount($post['old_balance_sum'] ?? 0));
        $this->line('post_target_balance_sum=' . $this->formatAmount($post['target_balance_sum'] ?? 0));
        $this->line('post_old_ledger_sum=' . $this->formatAmount($post['old_ledger_sum'] ?? 0));
        $this->line('post_target_ledger_sum=' . $this->formatAmount($post['target_ledger_sum'] ?? 0));
        $this->line('post_difference=' . $this->formatAmount($post['difference'] ?? 0));
        $this->line('reconciled=' . ((bool) ($post['reconciled'] ?? false) ? 'yes' : 'no'));

        $samples = is_array($result['sample_pairs'] ?? null) ? $result['sample_pairs'] : [];
        foreach ($samples as $index => $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $this->line(sprintf(
                'sample_pair_%d=old:%d target:%d source_id:%s wholesale_touched:%s source_id_count:%d',
                $index + 1,
                (int) ($pair['old_profile_id'] ?? 0),
                (int) ($pair['target_profile_id'] ?? 0),
                (string) ($pair['source_id'] ?? ''),
                (bool) ($pair['wholesale_touched'] ?? false) ? 'yes' : 'no',
                (int) ($pair['source_id_count'] ?? 0)
            ));
        }

        $ambiguousOldProfiles = (int) ($result['ambiguous_old_profiles'] ?? 0);
        $ambiguousTargetProfiles = (int) ($result['ambiguous_target_profiles'] ?? 0);

        if ($ambiguousOldProfiles > 0 || $ambiguousTargetProfiles > 0) {
            $this->warn('Ambiguous mappings were excluded (fail-closed). Resolve ambiguity before broad apply.');
        }

        if (! $apply && ((int) ($result['eligible_pairs'] ?? 0) > 0)) {
            $this->warn('Preview only. Re-run with --apply after verifying counts and samples.');
        }

        if ($apply) {
            return (bool) ($post['reconciled'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        return ($ambiguousOldProfiles > 0 || $ambiguousTargetProfiles > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    protected function normalizedStore(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function formatAmount(mixed $value): string
    {
        return number_format(CandleCashMeasurement::normalizeStoredAmount($value), 3, '.', '');
    }
}
