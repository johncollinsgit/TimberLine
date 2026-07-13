<?php

namespace App\Console\Commands;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerIdentityAuditService;
use App\Services\Marketing\CustomerMergeException;
use App\Services\Marketing\CustomerMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketingReconcileReviewedCustomerIdentity extends Command
{
    protected $signature = 'marketing:reconcile-reviewed-customer-identity
        {--tenant= : Tenant id or slug}
        {--store=retail : Shopify store key used for identity evidence}
        {--profiles= : Comma-separated reviewed profile ids}
        {--survivor= : Reviewed canonical profile id}
        {--expected-email= : Exact normalized email shared by every profile}
        {--apply : Apply the reviewed reconciliation}';

    protected $description = 'Fail-closed reconciliation for a reviewed customer identity cluster.';

    public function handle(CustomerIdentityAuditService $audit, CustomerMergeService $merge): int
    {
        $tenant = $this->resolveTenant();
        $profileIds = collect(explode(',', (string) $this->option('profiles')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $survivorId = (int) $this->option('survivor');
        $expectedEmail = strtolower(trim((string) $this->option('expected-email')));
        $storeKey = trim((string) $this->option('store')) ?: 'retail';

        if (! $tenant || $profileIds->count() < 2 || ! $profileIds->contains($survivorId) || $expectedEmail === '') {
            $this->error('Tenant, at least two profiles, a survivor, and an expected email are required.');

            return self::FAILURE;
        }

        $profiles = MarketingProfile::query()->withoutGlobalScopes()->whereIn('id', $profileIds)->get();
        if ($profiles->count() !== $profileIds->count()
            || $profiles->contains(fn (MarketingProfile $profile): bool => (int) $profile->tenant_id !== (int) $tenant->id)
            || $profiles->contains(fn (MarketingProfile $profile): bool => strtolower(trim((string) ($profile->normalized_email ?: $profile->email))) !== $expectedEmail)) {
            $this->error('The reviewed profiles no longer match the requested tenant and email.');

            return self::FAILURE;
        }

        if ($profiles->contains(fn (MarketingProfile $profile): bool => $profile->merged_at !== null)) {
            return $this->alreadyReconciled($profileIds, $survivorId);
        }

        $payload = $audit->audit((int) $tenant->id, (string) $tenant->slug, $storeKey, $expectedEmail);
        $cluster = collect($payload['results'])->first(function (array $candidate) use ($profileIds): bool {
            return collect($candidate['profile_ids'])->map('intval')->sort()->values()->all() === $profileIds->all();
        });

        if (! is_array($cluster) || (int) ($cluster['recommended_survivor_profile_id'] ?? 0) !== $survivorId) {
            $this->error('The live audit no longer recommends the reviewed survivor.');

            return self::FAILURE;
        }

        $reviewReasons = collect($cluster['review_reasons'] ?? []);
        $birthdayCompatible = $this->birthdaysAreCompatible(collect($cluster['profiles'] ?? []));
        $unsupportedReasons = $reviewReasons->reject(fn (string $reason): bool => $reason === 'conflicting_birthday_values' && $birthdayCompatible);
        if ($unsupportedReasons->isNotEmpty()) {
            $this->error('The live audit found unresolved conflicts: '.$unsupportedReasons->implode(', '));

            return self::FAILURE;
        }

        if ((bool) ($cluster['shopify_merge_required'] ?? false)) {
            $this->error('Multiple Shopify customers require the interactive Shopify merge workflow.');

            return self::FAILURE;
        }

        $quickBooksReferences = $this->quickBooksReferences((int) $tenant->id, $profileIds);
        if ($quickBooksReferences !== []) {
            $this->error('QuickBooks-linked customer records require separate review before reconciliation.');
            $this->line((string) json_encode(['quickbooks_references' => $quickBooksReferences], JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $preview = [
            'mode' => $this->option('apply') ? 'apply' : 'preview',
            'tenant_id' => (int) $tenant->id,
            'profile_ids' => $profileIds->all(),
            'survivor_profile_id' => $survivorId,
            'birthday_compatible' => $birthdayCompatible,
            'review_reasons_accepted' => $reviewReasons->values()->all(),
            'quickbooks_references' => [],
            'shopify_merge_required' => false,
            'before' => collect($cluster['profiles'])->map(fn (array $profile): array => [
                'id' => (int) $profile['id'],
                'balance' => (float) $profile['balance'],
                'ledger_net' => (float) $profile['ledger_net'],
                'transaction_count' => (int) $profile['transaction_count'],
                'birthday_count' => count($profile['birthdays']),
            ])->all(),
        ];

        if (! $this->option('apply')) {
            $this->line((string) json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $idempotencyKey = sprintf('reviewed-identity:%d:%s:%d', $tenant->id, $profileIds->implode('-'), $survivorId);
        $operation = $merge->createOperation(
            (int) $tenant->id,
            $profileIds->all(),
            $survivorId,
            $storeKey,
            $idempotencyKey,
            (array) ($cluster['field_sources'] ?? []),
            ['source' => 'production_maintenance_reviewed_identity']
        );

        try {
            $completed = $merge->apply($operation);
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => 'reconciliation_required',
                'errors' => [[
                    'code' => $exception instanceof CustomerMergeException ? $exception->publicCode() : 'reviewed_reconciliation_failed',
                    'message' => $exception->getMessage(),
                ]],
            ])->save();
            throw $exception;
        }

        $verification = $this->verification($profileIds, $survivorId);
        if (! $verification['verified']) {
            $completed->forceFill([
                'status' => 'reconciliation_required',
                'errors' => [['code' => 'post_reconciliation_verification_failed', 'message' => 'Post-reconciliation identity or ledger verification failed.']],
            ])->save();
            $this->error('The reconciliation completed but failed verification and requires review.');

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            ...$preview,
            'operation_id' => (int) $completed->id,
            'operation_status' => (string) $completed->status,
            'verification' => $verification,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $value = trim((string) $this->option('tenant'));

        return ctype_digit($value)
            ? Tenant::query()->find((int) $value)
            : Tenant::query()->where('slug', $value)->first();
    }

    private function birthdaysAreCompatible(Collection $profiles): bool
    {
        $birthdays = $profiles->flatMap(fn (array $profile): array => (array) ($profile['birthdays'] ?? []));
        if ($birthdays->isEmpty()) {
            return true;
        }

        $monthDays = $birthdays->map(fn (array $birthday): string => sprintf('%02d-%02d', (int) ($birthday['birth_month'] ?? 0), (int) ($birthday['birth_day'] ?? 0)))->unique();
        $years = $birthdays->pluck('birth_year')->filter()->map('intval')->unique();

        return $monthDays->count() === 1 && ! $monthDays->contains('00-00') && $years->count() <= 1;
    }

    /** @return array<string,int> */
    private function quickBooksReferences(int $tenantId, Collection $profileIds): array
    {
        $references = [];
        if (Schema::hasTable('marketing_profile_links')) {
            $count = DB::table('marketing_profile_links')->whereIn('marketing_profile_id', $profileIds)
                ->where('source_type', 'like', '%quickbooks%')->count();
            if ($count > 0) {
                $references['marketing_profile_links'] = $count;
            }
        }
        foreach (['field_service_financial_documents' => 'source', 'field_service_jobs' => 'external_source'] as $table => $sourceColumn) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'marketing_profile_id') || ! Schema::hasColumn($table, $sourceColumn)) {
                continue;
            }
            $count = DB::table($table)->where('tenant_id', $tenantId)->whereIn('marketing_profile_id', $profileIds)
                ->where($sourceColumn, 'like', '%quickbooks%')->count();
            if ($count > 0) {
                $references[$table] = $count;
            }
        }

        return $references;
    }

    private function alreadyReconciled(Collection $profileIds, int $survivorId): int
    {
        $verification = $this->verification($profileIds, $survivorId);
        if (! $verification['verified']) {
            $this->error('The profiles are partially merged and require reconciliation review.');

            return self::FAILURE;
        }
        $this->line((string) json_encode(['mode' => 'already_reconciled', 'verification' => $verification], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function verification(Collection $profileIds, int $survivorId): array
    {
        $survivor = MarketingProfile::query()->withoutGlobalScopes()->find($survivorId);
        $donors = MarketingProfile::query()->withoutGlobalScopes()->whereIn('id', $profileIds->reject(fn (int $id): bool => $id === $survivorId))->get();
        $ledgerNet = (float) DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivorId)->sum('candle_cash_delta');
        $balance = (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivorId)->value('balance');
        $birthdayCount = Schema::hasTable('customer_birthday_profiles')
            ? DB::table('customer_birthday_profiles')->where('marketing_profile_id', $survivorId)->count()
            : 0;
        $aliasesComplete = $donors->count() === $profileIds->count() - 1
            && $donors->every(fn (MarketingProfile $donor): bool => (int) $donor->merged_into_profile_id === $survivorId && $donor->merged_at !== null);

        return [
            'verified' => $survivor !== null && $survivor->merged_at === null && $aliasesComplete && abs($balance - $ledgerNet) < 0.005,
            'survivor_profile_id' => $survivorId,
            'alias_profile_ids' => $donors->pluck('id')->map('intval')->all(),
            'balance' => $balance,
            'ledger_net' => $ledgerNet,
            'transaction_count' => DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivorId)->count(),
            'birthday_profile_count' => $birthdayCount,
        ];
    }
}
