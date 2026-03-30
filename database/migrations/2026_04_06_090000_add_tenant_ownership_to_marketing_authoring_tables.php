<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addTenantColumns();

        $this->backfillCampaignTenantOwnership();
        $this->backfillSegmentTenantOwnership();
        $this->backfillTemplateTenantOwnership();
        $this->backfillEventSourceMappingTenantOwnership();
        $this->backfillEventAttributionTenantOwnership();

        $this->upgradeUniqueIndexes();
    }

    public function down(): void
    {
        $this->restoreLegacyUniqueIndexes();
        $this->dropTenantColumns();
    }

    protected function addTenantColumns(): void
    {
        $this->addTenantColumn('marketing_campaigns');
        $this->addTenantColumn('marketing_segments');
        $this->addTenantColumn('marketing_message_templates');
        $this->addTenantColumn('marketing_event_source_mappings');
        $this->addTenantColumn('marketing_order_event_attributions');
    }

    protected function addTenantColumn(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($table): void {
            $tableBlueprint->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $tableBlueprint->index('tenant_id', "{$table}_tenant_id_index");
        });

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($table): void {
                $tableBlueprint->foreign('tenant_id', "{$table}_tenant_id_foreign")
                    ->references('id')
                    ->on('tenants')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Safe no-op when foreign key already exists in some environments.
        }
    }

    protected function dropTenantColumns(): void
    {
        if ($this->usingSqlite()) {
            // SQLite cannot reliably drop FK-backed columns during test rollbacks.
            return;
        }

        $this->dropTenantColumn('marketing_order_event_attributions');
        $this->dropTenantColumn('marketing_event_source_mappings');
        $this->dropTenantColumn('marketing_message_templates');
        $this->dropTenantColumn('marketing_segments');
        $this->dropTenantColumn('marketing_campaigns');
    }

    protected function dropTenantColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($table): void {
                $tableBlueprint->dropForeign("{$table}_tenant_id_foreign");
            });
        } catch (\Throwable) {
            // Safe no-op when foreign key is absent.
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($table): void {
                $tableBlueprint->dropIndex("{$table}_tenant_id_index");
            });
        } catch (\Throwable) {
            // Safe no-op when index is absent.
        }

        Schema::table($table, function (Blueprint $tableBlueprint): void {
            $tableBlueprint->dropColumn('tenant_id');
        });
    }

    protected function backfillCampaignTenantOwnership(): void
    {
        if (! Schema::hasTable('marketing_campaigns') || ! Schema::hasColumn('marketing_campaigns', 'tenant_id')) {
            return;
        }

        $evidence = $this->campaignOwnershipEvidenceQuery();
        if (! $evidence instanceof QueryBuilder) {
            return;
        }

        $resolvedRows = DB::query()
            ->fromSub($evidence, 'campaign_ownership')
            ->selectRaw('campaign_ownership.entity_id as campaign_id, min(campaign_ownership.tenant_id) as tenant_id')
            ->groupBy('campaign_ownership.entity_id')
            ->havingRaw('count(distinct campaign_ownership.tenant_id) = 1')
            ->get();

        foreach ($resolvedRows as $row) {
            $campaignId = (int) ($row->campaign_id ?? 0);
            $tenantId = (int) ($row->tenant_id ?? 0);

            if ($campaignId <= 0 || $tenantId <= 0) {
                continue;
            }

            DB::table('marketing_campaigns')
                ->where('id', $campaignId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
    }

    protected function backfillSegmentTenantOwnership(): void
    {
        if (! Schema::hasTable('marketing_segments') || ! Schema::hasColumn('marketing_segments', 'tenant_id')) {
            return;
        }

        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        $resolvedRows = DB::table('marketing_campaigns')
            ->whereNotNull('segment_id')
            ->whereNotNull('tenant_id')
            ->selectRaw('segment_id as segment_id, min(tenant_id) as tenant_id')
            ->groupBy('segment_id')
            ->havingRaw('count(distinct tenant_id) = 1')
            ->get();

        foreach ($resolvedRows as $row) {
            $segmentId = (int) ($row->segment_id ?? 0);
            $tenantId = (int) ($row->tenant_id ?? 0);

            if ($segmentId <= 0 || $tenantId <= 0) {
                continue;
            }

            DB::table('marketing_segments')
                ->where('id', $segmentId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
    }

    protected function backfillTemplateTenantOwnership(): void
    {
        if (! Schema::hasTable('marketing_message_templates') || ! Schema::hasColumn('marketing_message_templates', 'tenant_id')) {
            return;
        }

        if (! Schema::hasTable('marketing_campaign_variants') || ! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        $resolvedRows = DB::table('marketing_campaign_variants as mcv')
            ->join('marketing_campaigns as mc', 'mc.id', '=', 'mcv.campaign_id')
            ->whereNotNull('mcv.template_id')
            ->whereNotNull('mc.tenant_id')
            ->selectRaw('mcv.template_id as template_id, min(mc.tenant_id) as tenant_id')
            ->groupBy('mcv.template_id')
            ->havingRaw('count(distinct mc.tenant_id) = 1')
            ->get();

        foreach ($resolvedRows as $row) {
            $templateId = (int) ($row->template_id ?? 0);
            $tenantId = (int) ($row->tenant_id ?? 0);

            if ($templateId <= 0 || $tenantId <= 0) {
                continue;
            }

            DB::table('marketing_message_templates')
                ->where('id', $templateId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
    }

    protected function backfillEventSourceMappingTenantOwnership(): void
    {
        if (! Schema::hasTable('marketing_event_source_mappings') || ! Schema::hasColumn('marketing_event_source_mappings', 'tenant_id')) {
            return;
        }

        if (! Schema::hasTable('square_orders') || ! Schema::hasColumn('square_orders', 'tenant_id')) {
            return;
        }

        DB::table('marketing_event_source_mappings')
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->chunkById(200, function (Collection $mappings): void {
                foreach ($mappings as $mapping) {
                    $mappingId = (int) ($mapping->id ?? 0);
                    if ($mappingId <= 0) {
                        continue;
                    }

                    $tenantId = $this->resolvedMappingTenantId($mapping);
                    if ($tenantId === null) {
                        continue;
                    }

                    DB::table('marketing_event_source_mappings')
                        ->where('id', $mappingId)
                        ->whereNull('tenant_id')
                        ->update(['tenant_id' => $tenantId]);
                }
            });
    }

    protected function backfillEventAttributionTenantOwnership(): void
    {
        if (! Schema::hasTable('marketing_order_event_attributions') || ! Schema::hasColumn('marketing_order_event_attributions', 'tenant_id')) {
            return;
        }

        if (! Schema::hasTable('square_orders') || ! Schema::hasColumn('square_orders', 'tenant_id')) {
            return;
        }

        $resolvedRows = DB::table('marketing_order_event_attributions as moea')
            ->join('square_orders as so', 'so.square_order_id', '=', 'moea.source_id')
            ->where('moea.source_type', 'square_order')
            ->whereNull('moea.tenant_id')
            ->whereNotNull('so.tenant_id')
            ->selectRaw('moea.id as attribution_id, min(so.tenant_id) as tenant_id')
            ->groupBy('moea.id')
            ->havingRaw('count(distinct so.tenant_id) = 1')
            ->get();

        foreach ($resolvedRows as $row) {
            $attributionId = (int) ($row->attribution_id ?? 0);
            $tenantId = (int) ($row->tenant_id ?? 0);

            if ($attributionId <= 0 || $tenantId <= 0) {
                continue;
            }

            DB::table('marketing_order_event_attributions')
                ->where('id', $attributionId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
    }

    protected function upgradeUniqueIndexes(): void
    {
        if (Schema::hasTable('marketing_campaigns')) {
            $this->dropUniqueIndex('marketing_campaigns', 'marketing_campaigns_slug_unique');
            $this->addUniqueIndex('marketing_campaigns', ['tenant_id', 'slug'], 'marketing_campaigns_tenant_slug_unique');
        }

        if (Schema::hasTable('marketing_segments')) {
            $this->dropUniqueIndex('marketing_segments', 'marketing_segments_slug_unique');
            $this->addUniqueIndex('marketing_segments', ['tenant_id', 'slug'], 'marketing_segments_tenant_slug_unique');
        }

        if (Schema::hasTable('marketing_event_source_mappings')) {
            $this->dropUniqueIndex('marketing_event_source_mappings', 'mesm_source_raw_unique_idx');
            $this->addUniqueIndex('marketing_event_source_mappings', ['tenant_id', 'source_system', 'raw_value'], 'mesm_tenant_source_raw_unique_idx');
        }

        if (Schema::hasTable('marketing_order_event_attributions')) {
            $this->dropUniqueIndex('marketing_order_event_attributions', 'moea_source_event_unique');
            $this->addUniqueIndex('marketing_order_event_attributions', ['tenant_id', 'source_type', 'source_id', 'event_instance_id'], 'moea_tenant_source_event_unique');
        }
    }

    protected function restoreLegacyUniqueIndexes(): void
    {
        if (Schema::hasTable('marketing_order_event_attributions')) {
            $this->dropUniqueIndex('marketing_order_event_attributions', 'moea_tenant_source_event_unique');
            $this->addUniqueIndex('marketing_order_event_attributions', ['source_type', 'source_id', 'event_instance_id'], 'moea_source_event_unique');
        }

        if (Schema::hasTable('marketing_event_source_mappings')) {
            $this->dropUniqueIndex('marketing_event_source_mappings', 'mesm_tenant_source_raw_unique_idx');
            $this->addUniqueIndex('marketing_event_source_mappings', ['source_system', 'raw_value'], 'mesm_source_raw_unique_idx');
        }

        if (Schema::hasTable('marketing_segments')) {
            $this->dropUniqueIndex('marketing_segments', 'marketing_segments_tenant_slug_unique');
            $this->addUniqueIndex('marketing_segments', ['slug'], 'marketing_segments_slug_unique');
        }

        if (Schema::hasTable('marketing_campaigns')) {
            $this->dropUniqueIndex('marketing_campaigns', 'marketing_campaigns_tenant_slug_unique');
            $this->addUniqueIndex('marketing_campaigns', ['slug'], 'marketing_campaigns_slug_unique');
        }
    }

    /**
     * @param array<int,string> $columns
     */
    protected function addUniqueIndex(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $name): void {
                $tableBlueprint->unique($columns, $name);
            });
        } catch (\Throwable) {
            // Safe no-op when index already exists in current environment.
        }
    }

    protected function dropUniqueIndex(string $table, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($name): void {
                $tableBlueprint->dropUnique($name);
            });
        } catch (\Throwable) {
            // Safe no-op when index is absent in current environment.
        }
    }

    protected function resolvedMappingTenantId(object $mapping): ?int
    {
        $sourceSystem = strtolower(trim((string) ($mapping->source_system ?? '')));
        if ($sourceSystem === '') {
            return null;
        }

        $candidates = collect([
            $this->normalizeValue((string) ($mapping->raw_value ?? '')),
            $this->normalizeValue((string) ($mapping->normalized_value ?? '')),
        ])->filter()->unique()->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        return match ($sourceSystem) {
            'square_source_name' => $this->resolvedSourceNameTenantId($candidates),
            'square_tax_name' => $this->resolvedTaxNameTenantId($candidates),
            default => null,
        };
    }

    protected function resolvedSourceNameTenantId(Collection $candidates): ?int
    {
        $tenantIds = DB::table('square_orders')
            ->whereNotNull('tenant_id')
            ->where(function (QueryBuilder $query) use ($candidates): void {
                $query->whereIn(
                    DB::raw('lower(trim(coalesce(source_name, "")))'),
                    $candidates->all()
                );
            })
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        return $tenantIds->count() === 1 ? (int) $tenantIds->first() : null;
    }

    protected function resolvedTaxNameTenantId(Collection $candidates): ?int
    {
        $tenantIds = collect();

        DB::table('square_orders')
            ->select(['id', 'tenant_id', 'raw_tax_names'])
            ->whereNotNull('tenant_id')
            ->whereNotNull('raw_tax_names')
            ->orderBy('id')
            ->chunkById(500, function (Collection $orders) use ($candidates, &$tenantIds): bool {
                foreach ($orders as $order) {
                    $tenantId = (int) ($order->tenant_id ?? 0);
                    if ($tenantId <= 0) {
                        continue;
                    }

                    foreach ($this->taxNamesFromSquareOrder($order->raw_tax_names) as $taxName) {
                        if (! $candidates->contains($this->normalizeValue($taxName))) {
                            continue;
                        }

                        $tenantIds->push($tenantId);
                        $tenantIds = $tenantIds->unique()->values();
                        break;
                    }

                    if ($tenantIds->count() > 1) {
                        return false;
                    }
                }

                return true;
            });

        return $tenantIds->count() === 1 ? (int) $tenantIds->first() : null;
    }

    /**
     * @return array<int,string>
     */
    protected function taxNamesFromSquareOrder(mixed $rawTaxNames): array
    {
        if (is_array($rawTaxNames)) {
            return collect($rawTaxNames)
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->values()
                ->all();
        }

        if (! is_string($rawTaxNames)) {
            return [];
        }

        $decoded = json_decode($rawTaxNames, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function campaignOwnershipEvidenceQuery(): ?QueryBuilder
    {
        $sources = [];

        if (Schema::hasTable('marketing_campaign_recipients') && Schema::hasTable('marketing_profiles')) {
            $sources[] = DB::table('marketing_campaign_recipients as mcr')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mcr.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mcr.campaign_id as entity_id, mp.tenant_id as tenant_id');
        }

        if (
            Schema::hasTable('marketing_campaign_groups')
            && Schema::hasTable('marketing_group_members')
            && Schema::hasTable('marketing_profiles')
        ) {
            $sources[] = DB::table('marketing_campaign_groups as mcg')
                ->join('marketing_group_members as mgm', 'mgm.marketing_group_id', '=', 'mcg.marketing_group_id')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mgm.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mcg.campaign_id as entity_id, mp.tenant_id as tenant_id');
        }

        if (Schema::hasTable('marketing_campaign_conversions') && Schema::hasTable('marketing_profiles')) {
            $sources[] = DB::table('marketing_campaign_conversions as mcc')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mcc.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mcc.campaign_id as entity_id, mp.tenant_id as tenant_id');
        }

        return $this->unionEvidence($sources);
    }

    /**
     * @param array<int,QueryBuilder> $sources
     */
    protected function unionEvidence(array $sources): ?QueryBuilder
    {
        if ($sources === []) {
            return null;
        }

        $query = array_shift($sources);

        foreach ($sources as $source) {
            $query->unionAll($source);
        }

        return $query;
    }

    protected function usingSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
