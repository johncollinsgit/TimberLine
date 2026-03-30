<?php

namespace App\Services\Marketing;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingAuthoringOwnershipRemediationService
{
    public const ENTITY_CAMPAIGNS = 'campaigns';
    public const ENTITY_SEGMENTS = 'segments';
    public const ENTITY_TEMPLATES = 'templates';
    public const ENTITY_MAPPINGS = 'mappings';
    public const ENTITY_ATTRIBUTIONS = 'attributions';

    /**
     * @var array<int,string>
     */
    public const ENTITIES = [
        self::ENTITY_CAMPAIGNS,
        self::ENTITY_SEGMENTS,
        self::ENTITY_TEMPLATES,
        self::ENTITY_MAPPINGS,
        self::ENTITY_ATTRIBUTIONS,
    ];

    /**
     * @var array{
     *   source_name:array<string,array<int,int>>,
     *   tax_name:array<string,array<int,int>>
     * }|null
     */
    protected ?array $squareEvidenceMaps = null;

    /**
     * @var array<string,array<int,int>>|null
     */
    protected ?array $squareOrderTenantMap = null;

    /**
     * @param array<int,string> $entities
     * @return array{
     *   mode:string,
     *   entities:array<string,array<string,mixed>>,
     *   totals:array{
     *     unresolved_total:int,
     *     evaluated:int,
     *     provable:int,
     *     assigned:int,
     *     ambiguous:int,
     *     unprovable:int,
     *     unsupported:int,
     *     remaining_unresolved:int
     *   }
     * }
     */
    public function run(
        bool $apply = false,
        array $entities = self::ENTITIES,
        int $sampleLimit = 20,
        ?int $limitPerEntity = null
    ): array {
        $sampleLimit = max(1, min(200, $sampleLimit));
        $entities = $this->normalizeEntities($entities);

        $summaries = [];
        foreach ($entities as $entity) {
            $summary = match ($entity) {
                self::ENTITY_CAMPAIGNS => $this->remediateCampaigns($apply, $sampleLimit, $limitPerEntity),
                self::ENTITY_SEGMENTS => $this->remediateSegments($apply, $sampleLimit, $limitPerEntity),
                self::ENTITY_TEMPLATES => $this->remediateTemplates($apply, $sampleLimit, $limitPerEntity),
                self::ENTITY_MAPPINGS => $this->remediateMappings($apply, $sampleLimit, $limitPerEntity),
                self::ENTITY_ATTRIBUTIONS => $this->remediateAttributions($apply, $sampleLimit, $limitPerEntity),
                default => null,
            };

            if (is_array($summary)) {
                $summaries[$entity] = $summary;
            }
        }

        $totals = [
            'unresolved_total' => 0,
            'evaluated' => 0,
            'provable' => 0,
            'assigned' => 0,
            'ambiguous' => 0,
            'unprovable' => 0,
            'unsupported' => 0,
            'remaining_unresolved' => 0,
        ];

        foreach ($summaries as $summary) {
            $totals['unresolved_total'] += (int) ($summary['unresolved_total'] ?? 0);
            $totals['evaluated'] += (int) ($summary['evaluated'] ?? 0);
            $totals['provable'] += (int) ($summary['provable'] ?? 0);
            $totals['assigned'] += (int) ($summary['assigned'] ?? 0);
            $totals['ambiguous'] += (int) ($summary['ambiguous'] ?? 0);
            $totals['unprovable'] += (int) ($summary['unprovable'] ?? 0);
            $totals['unsupported'] += (int) ($summary['unsupported'] ?? 0);
            $totals['remaining_unresolved'] += (int) ($summary['remaining_unresolved'] ?? 0);
        }

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'entities' => $summaries,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<int,string> $entities
     * @return array<int,string>
     */
    protected function normalizeEntities(array $entities): array
    {
        return collect($entities)
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter(fn (string $value): bool => in_array($value, self::ENTITIES, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function remediateCampaigns(bool $apply, int $sampleLimit, ?int $limitPerEntity): array
    {
        $summary = $this->baseSummary(
            entity: self::ENTITY_CAMPAIGNS,
            table: 'marketing_campaigns',
            rail: 'campaign_profile_group_conversion'
        );

        if ((bool) ($summary['skipped'] ?? false)) {
            return $summary;
        }

        $stats = $this->campaignTenantStats();

        return $this->evaluateUnresolvedRows(
            table: 'marketing_campaigns',
            summary: $summary,
            apply: $apply,
            sampleLimit: $sampleLimit,
            limitPerEntity: $limitPerEntity,
            resolver: function (object $row) use ($stats): array {
                $campaignId = (int) ($row->id ?? 0);
                $tenantStats = $stats[$campaignId] ?? null;
                if ($tenantStats === null) {
                    return [
                        'status' => 'unprovable',
                        'reason' => 'no_campaign_owner_evidence',
                        'rail' => 'campaign_profile_group_conversion',
                    ];
                }

                $tenantCount = (int) ($tenantStats['tenant_count'] ?? 0);
                $tenantId = (int) ($tenantStats['tenant_id'] ?? 0);
                if ($tenantCount === 1 && $tenantId > 0) {
                    return [
                        'status' => 'provable',
                        'tenant_id' => $tenantId,
                        'reason' => 'single_campaign_owner',
                        'rail' => 'campaign_profile_group_conversion',
                    ];
                }

                if ($tenantCount > 1) {
                    return [
                        'status' => 'ambiguous',
                        'reason' => 'mixed_campaign_owner_evidence',
                        'rail' => 'campaign_profile_group_conversion',
                        'tenant_count' => $tenantCount,
                    ];
                }

                return [
                    'status' => 'unprovable',
                    'reason' => 'no_campaign_owner_evidence',
                    'rail' => 'campaign_profile_group_conversion',
                ];
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function remediateSegments(bool $apply, int $sampleLimit, ?int $limitPerEntity): array
    {
        $summary = $this->baseSummary(
            entity: self::ENTITY_SEGMENTS,
            table: 'marketing_segments',
            rail: 'tenant_campaign_segment_bridge'
        );

        if ((bool) ($summary['skipped'] ?? false)) {
            return $summary;
        }

        $stats = $this->segmentTenantStats();

        return $this->evaluateUnresolvedRows(
            table: 'marketing_segments',
            summary: $summary,
            apply: $apply,
            sampleLimit: $sampleLimit,
            limitPerEntity: $limitPerEntity,
            resolver: function (object $row) use ($stats): array {
                $segmentId = (int) ($row->id ?? 0);
                $tenantStats = $stats[$segmentId] ?? null;
                if ($tenantStats === null) {
                    return [
                        'status' => 'unprovable',
                        'reason' => 'no_segment_owner_evidence',
                        'rail' => 'tenant_campaign_segment_bridge',
                    ];
                }

                $tenantCount = (int) ($tenantStats['tenant_count'] ?? 0);
                $tenantId = (int) ($tenantStats['tenant_id'] ?? 0);
                if ($tenantCount === 1 && $tenantId > 0) {
                    return [
                        'status' => 'provable',
                        'tenant_id' => $tenantId,
                        'reason' => 'single_segment_owner',
                        'rail' => 'tenant_campaign_segment_bridge',
                    ];
                }

                if ($tenantCount > 1) {
                    return [
                        'status' => 'ambiguous',
                        'reason' => 'mixed_segment_owner_evidence',
                        'rail' => 'tenant_campaign_segment_bridge',
                        'tenant_count' => $tenantCount,
                    ];
                }

                return [
                    'status' => 'unprovable',
                    'reason' => 'no_segment_owner_evidence',
                    'rail' => 'tenant_campaign_segment_bridge',
                ];
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function remediateTemplates(bool $apply, int $sampleLimit, ?int $limitPerEntity): array
    {
        $summary = $this->baseSummary(
            entity: self::ENTITY_TEMPLATES,
            table: 'marketing_message_templates',
            rail: 'tenant_campaign_variant_template_bridge'
        );

        if ((bool) ($summary['skipped'] ?? false)) {
            return $summary;
        }

        $stats = $this->templateTenantStats();

        return $this->evaluateUnresolvedRows(
            table: 'marketing_message_templates',
            summary: $summary,
            apply: $apply,
            sampleLimit: $sampleLimit,
            limitPerEntity: $limitPerEntity,
            resolver: function (object $row) use ($stats): array {
                $templateId = (int) ($row->id ?? 0);
                $tenantStats = $stats[$templateId] ?? null;
                if ($tenantStats === null) {
                    return [
                        'status' => 'unprovable',
                        'reason' => 'no_template_owner_evidence',
                        'rail' => 'tenant_campaign_variant_template_bridge',
                    ];
                }

                $tenantCount = (int) ($tenantStats['tenant_count'] ?? 0);
                $tenantId = (int) ($tenantStats['tenant_id'] ?? 0);
                if ($tenantCount === 1 && $tenantId > 0) {
                    return [
                        'status' => 'provable',
                        'tenant_id' => $tenantId,
                        'reason' => 'single_template_owner',
                        'rail' => 'tenant_campaign_variant_template_bridge',
                    ];
                }

                if ($tenantCount > 1) {
                    return [
                        'status' => 'ambiguous',
                        'reason' => 'mixed_template_owner_evidence',
                        'rail' => 'tenant_campaign_variant_template_bridge',
                        'tenant_count' => $tenantCount,
                    ];
                }

                return [
                    'status' => 'unprovable',
                    'reason' => 'no_template_owner_evidence',
                    'rail' => 'tenant_campaign_variant_template_bridge',
                ];
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function remediateMappings(bool $apply, int $sampleLimit, ?int $limitPerEntity): array
    {
        $summary = $this->baseSummary(
            entity: self::ENTITY_MAPPINGS,
            table: 'marketing_event_source_mappings',
            rail: 'square_source_name_or_tax_name_owner'
        );

        if ((bool) ($summary['skipped'] ?? false)) {
            return $summary;
        }

        $maps = $this->squareEvidenceMaps();

        return $this->evaluateUnresolvedRows(
            table: 'marketing_event_source_mappings',
            summary: $summary,
            apply: $apply,
            sampleLimit: $sampleLimit,
            limitPerEntity: $limitPerEntity,
            resolver: function (object $row) use ($maps): array {
                $sourceSystem = strtolower(trim((string) ($row->source_system ?? '')));
                $rawValue = $this->normalizeText((string) ($row->raw_value ?? ''));
                $normalizedValue = $this->normalizeText((string) ($row->normalized_value ?? ''));
                $candidateValues = collect([$rawValue, $normalizedValue])
                    ->filter()
                    ->unique()
                    ->values();

                if ($candidateValues->isEmpty()) {
                    return [
                        'status' => 'unprovable',
                        'reason' => 'empty_mapping_value',
                        'rail' => 'square_source_name_or_tax_name_owner',
                    ];
                }

                if ($sourceSystem === 'square_source_name') {
                    $tenantIds = $this->mappedTenantIds($maps['source_name'], $candidateValues);

                    return $this->ownershipStatusFromTenantIds(
                        $tenantIds,
                        rail: 'square_source_name_owner',
                        unprovableReason: 'no_square_source_owner_evidence',
                        ambiguousReason: 'mixed_square_source_owner_evidence'
                    );
                }

                if ($sourceSystem === 'square_tax_name') {
                    $tenantIds = $this->mappedTenantIds($maps['tax_name'], $candidateValues);

                    return $this->ownershipStatusFromTenantIds(
                        $tenantIds,
                        rail: 'square_tax_name_owner',
                        unprovableReason: 'no_square_tax_owner_evidence',
                        ambiguousReason: 'mixed_square_tax_owner_evidence'
                    );
                }

                return [
                    'status' => 'unsupported',
                    'reason' => 'unsupported_mapping_source_system',
                    'rail' => 'mapping_source_system_not_supported_for_safe_owner_proof',
                    'source_system' => $sourceSystem,
                ];
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function remediateAttributions(bool $apply, int $sampleLimit, ?int $limitPerEntity): array
    {
        $summary = $this->baseSummary(
            entity: self::ENTITY_ATTRIBUTIONS,
            table: 'marketing_order_event_attributions',
            rail: 'tenant_square_order_source_bridge'
        );

        if ((bool) ($summary['skipped'] ?? false)) {
            return $summary;
        }

        $sourceTenantMap = $this->squareOrderTenantMap();

        return $this->evaluateUnresolvedRows(
            table: 'marketing_order_event_attributions',
            summary: $summary,
            apply: $apply,
            sampleLimit: $sampleLimit,
            limitPerEntity: $limitPerEntity,
            resolver: function (object $row) use ($sourceTenantMap): array {
                $sourceType = strtolower(trim((string) ($row->source_type ?? '')));
                $sourceId = trim((string) ($row->source_id ?? ''));
                if ($sourceType !== 'square_order') {
                    return [
                        'status' => 'unsupported',
                        'reason' => 'unsupported_attribution_source_type',
                        'rail' => 'source_type_not_supported_for_safe_owner_proof',
                        'source_type' => $sourceType,
                    ];
                }

                if ($sourceId === '') {
                    return [
                        'status' => 'unprovable',
                        'reason' => 'missing_attribution_source_id',
                        'rail' => 'tenant_square_order_source_bridge',
                    ];
                }

                $tenantIds = collect($sourceTenantMap[$sourceId] ?? [])
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values()
                    ->all();

                return $this->ownershipStatusFromTenantIds(
                    $tenantIds,
                    rail: 'tenant_square_order_source_bridge',
                    unprovableReason: 'no_square_order_owner_evidence',
                    ambiguousReason: 'mixed_square_order_owner_evidence'
                );
            }
        );
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    protected function evaluateUnresolvedRows(
        string $table,
        array $summary,
        bool $apply,
        int $sampleLimit,
        ?int $limitPerEntity,
        callable $resolver
    ): array {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            $summary['skipped'] = true;
            $summary['reason'] = 'table_or_tenant_column_missing';

            return $summary;
        }

        $summary['unresolved_total'] = (int) DB::table($table)
            ->whereNull('tenant_id')
            ->count();

        if ($summary['unresolved_total'] === 0) {
            $summary['remaining_unresolved'] = 0;

            return $summary;
        }

        $evaluated = 0;
        $query = DB::table($table)
            ->whereNull('tenant_id')
            ->orderBy('id');

        $query->chunkById(250, function (Collection $rows) use (
            $table,
            &$summary,
            &$evaluated,
            $apply,
            $sampleLimit,
            $limitPerEntity,
            $resolver
        ): bool {
            foreach ($rows as $row) {
                if ($limitPerEntity !== null && $evaluated >= $limitPerEntity) {
                    return false;
                }

                $evaluated++;
                $classification = (array) $resolver($row);
                $status = (string) ($classification['status'] ?? 'unprovable');

                if (! in_array($status, ['provable', 'ambiguous', 'unprovable', 'unsupported'], true)) {
                    $status = 'unprovable';
                }

                $summary[$status] = (int) ($summary[$status] ?? 0) + 1;
                $this->addSample($summary, $status, $row, $classification, $sampleLimit);

                if ($status !== 'provable' || ! $apply) {
                    continue;
                }

                $tenantId = is_numeric($classification['tenant_id'] ?? null)
                    ? (int) $classification['tenant_id']
                    : 0;
                if ($tenantId <= 0) {
                    continue;
                }

                $updated = DB::table($table)
                    ->where('id', (int) ($row->id ?? 0))
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenantId]);

                if ($updated > 0) {
                    $summary['assigned'] = (int) ($summary['assigned'] ?? 0) + 1;
                }
            }

            return true;
        }, 'id');

        $summary['evaluated'] = $evaluated;
        $summary['remaining_unresolved'] = max(
            0,
            (int) $summary['unresolved_total'] - (int) ($summary['assigned'] ?? 0)
        );

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    protected function baseSummary(string $entity, string $table, string $rail): array
    {
        $hasTable = Schema::hasTable($table);
        $hasTenantColumn = $hasTable && Schema::hasColumn($table, 'tenant_id');

        return [
            'entity' => $entity,
            'table' => $table,
            'rail' => $rail,
            'skipped' => ! $hasTable || ! $hasTenantColumn,
            'reason' => ! $hasTable || ! $hasTenantColumn
                ? 'table_or_tenant_column_missing'
                : null,
            'unresolved_total' => 0,
            'evaluated' => 0,
            'provable' => 0,
            'assigned' => 0,
            'ambiguous' => 0,
            'unprovable' => 0,
            'unsupported' => 0,
            'remaining_unresolved' => 0,
            'sample' => [
                'provable' => [],
                'ambiguous' => [],
                'unprovable' => [],
                'unsupported' => [],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $classification
     */
    protected function addSample(
        array &$summary,
        string $status,
        object $row,
        array $classification,
        int $sampleLimit
    ): void {
        if (! isset($summary['sample'][$status]) || ! is_array($summary['sample'][$status])) {
            return;
        }

        if (count($summary['sample'][$status]) >= $sampleLimit) {
            return;
        }

        $sample = [
            'id' => (int) ($row->id ?? 0),
            'reason' => (string) ($classification['reason'] ?? ''),
            'rail' => (string) ($classification['rail'] ?? ''),
        ];

        if (is_numeric($classification['tenant_id'] ?? null) && (int) $classification['tenant_id'] > 0) {
            $sample['tenant_id'] = (int) $classification['tenant_id'];
        }

        if (is_numeric($classification['tenant_count'] ?? null) && (int) $classification['tenant_count'] > 0) {
            $sample['tenant_count'] = (int) $classification['tenant_count'];
        }

        if (is_string($classification['source_system'] ?? null) && trim((string) $classification['source_system']) !== '') {
            $sample['source_system'] = trim((string) $classification['source_system']);
        }

        if (is_string($classification['source_type'] ?? null) && trim((string) $classification['source_type']) !== '') {
            $sample['source_type'] = trim((string) $classification['source_type']);
        }

        $summary['sample'][$status][] = $sample;
    }

    /**
     * @return array<int,array{tenant_id:int,tenant_count:int}>
     */
    protected function campaignTenantStats(): array
    {
        $evidence = $this->campaignEvidenceQuery();
        if (! $evidence instanceof QueryBuilder) {
            return [];
        }

        return DB::query()
            ->fromSub($evidence, 'campaign_owner_evidence')
            ->selectRaw('campaign_owner_evidence.entity_id as id, min(campaign_owner_evidence.tenant_id) as tenant_id, count(distinct campaign_owner_evidence.tenant_id) as tenant_count')
            ->groupBy('campaign_owner_evidence.entity_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) ($row->id ?? 0) => [
                    'tenant_id' => (int) ($row->tenant_id ?? 0),
                    'tenant_count' => (int) ($row->tenant_count ?? 0),
                ],
            ])
            ->all();
    }

    /**
     * @return array<int,array{tenant_id:int,tenant_count:int}>
     */
    protected function segmentTenantStats(): array
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return [];
        }

        return DB::table('marketing_campaigns')
            ->whereNotNull('segment_id')
            ->whereNotNull('tenant_id')
            ->selectRaw('segment_id as id, min(tenant_id) as tenant_id, count(distinct tenant_id) as tenant_count')
            ->groupBy('segment_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) ($row->id ?? 0) => [
                    'tenant_id' => (int) ($row->tenant_id ?? 0),
                    'tenant_count' => (int) ($row->tenant_count ?? 0),
                ],
            ])
            ->all();
    }

    /**
     * @return array<int,array{tenant_id:int,tenant_count:int}>
     */
    protected function templateTenantStats(): array
    {
        if (! Schema::hasTable('marketing_campaign_variants') || ! Schema::hasTable('marketing_campaigns')) {
            return [];
        }

        return DB::table('marketing_campaign_variants as mcv')
            ->join('marketing_campaigns as mc', 'mc.id', '=', 'mcv.campaign_id')
            ->whereNotNull('mcv.template_id')
            ->whereNotNull('mc.tenant_id')
            ->selectRaw('mcv.template_id as id, min(mc.tenant_id) as tenant_id, count(distinct mc.tenant_id) as tenant_count')
            ->groupBy('mcv.template_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) ($row->id ?? 0) => [
                    'tenant_id' => (int) ($row->tenant_id ?? 0),
                    'tenant_count' => (int) ($row->tenant_count ?? 0),
                ],
            ])
            ->all();
    }

    /**
     * @return QueryBuilder|null
     */
    protected function campaignEvidenceQuery(): ?QueryBuilder
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

        if ($sources === []) {
            return null;
        }

        $query = array_shift($sources);
        foreach ($sources as $source) {
            $query->unionAll($source);
        }

        return $query;
    }

    /**
     * @return array{
     *   source_name:array<string,array<int,int>>,
     *   tax_name:array<string,array<int,int>>
     * }
     */
    protected function squareEvidenceMaps(): array
    {
        if (is_array($this->squareEvidenceMaps)) {
            return $this->squareEvidenceMaps;
        }

        $maps = [
            'source_name' => [],
            'tax_name' => [],
        ];

        if (
            ! Schema::hasTable('square_orders')
            || ! Schema::hasColumn('square_orders', 'tenant_id')
        ) {
            $this->squareEvidenceMaps = $maps;

            return $maps;
        }

        DB::table('square_orders')
            ->select(['id', 'tenant_id', 'source_name', 'raw_tax_names'])
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $orders) use (&$maps): void {
                foreach ($orders as $order) {
                    $tenantId = (int) ($order->tenant_id ?? 0);
                    if ($tenantId <= 0) {
                        continue;
                    }

                    $sourceName = $this->normalizeText((string) ($order->source_name ?? ''));
                    if ($sourceName !== '') {
                        $maps['source_name'][$sourceName] = $this->appendTenantId($maps['source_name'][$sourceName] ?? [], $tenantId);
                    }

                    foreach ($this->taxNamesFromSquareOrder($order->raw_tax_names ?? null) as $taxName) {
                        $normalizedTax = $this->normalizeText($taxName);
                        if ($normalizedTax === '') {
                            continue;
                        }

                        $maps['tax_name'][$normalizedTax] = $this->appendTenantId($maps['tax_name'][$normalizedTax] ?? [], $tenantId);
                    }
                }
            });

        $this->squareEvidenceMaps = $maps;

        return $maps;
    }

    /**
     * @return array<string,array<int,int>>
     */
    protected function squareOrderTenantMap(): array
    {
        if (is_array($this->squareOrderTenantMap)) {
            return $this->squareOrderTenantMap;
        }

        $map = [];

        if (
            ! Schema::hasTable('square_orders')
            || ! Schema::hasColumn('square_orders', 'tenant_id')
            || ! Schema::hasColumn('square_orders', 'square_order_id')
        ) {
            $this->squareOrderTenantMap = $map;

            return $map;
        }

        DB::table('square_orders')
            ->select(['id', 'square_order_id', 'tenant_id'])
            ->whereNotNull('square_order_id')
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->chunkById(1000, function (Collection $orders) use (&$map): void {
                foreach ($orders as $order) {
                    $squareOrderId = trim((string) ($order->square_order_id ?? ''));
                    $tenantId = (int) ($order->tenant_id ?? 0);
                    if ($squareOrderId === '' || $tenantId <= 0) {
                        continue;
                    }

                    $map[$squareOrderId] = $this->appendTenantId($map[$squareOrderId] ?? [], $tenantId);
                }
            });

        $this->squareOrderTenantMap = $map;

        return $map;
    }

    /**
     * @param array<string,array<int,int>> $map
     * @param Collection<int,string> $candidates
     * @return array<int,int>
     */
    protected function mappedTenantIds(array $map, Collection $candidates): array
    {
        $tenantIds = collect();
        foreach ($candidates as $candidate) {
            foreach ($map[$candidate] ?? [] as $tenantId) {
                $tenantIds->push((int) $tenantId);
            }
        }

        return $tenantIds
            ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,int> $tenantIds
     * @return array<string,mixed>
     */
    protected function ownershipStatusFromTenantIds(
        array $tenantIds,
        string $rail,
        string $unprovableReason,
        string $ambiguousReason
    ): array {
        $uniqueTenantIds = collect($tenantIds)
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($uniqueTenantIds->count() === 1) {
            return [
                'status' => 'provable',
                'tenant_id' => (int) $uniqueTenantIds->first(),
                'reason' => 'single_owner',
                'rail' => $rail,
            ];
        }

        if ($uniqueTenantIds->count() > 1) {
            return [
                'status' => 'ambiguous',
                'reason' => $ambiguousReason,
                'rail' => $rail,
                'tenant_count' => $uniqueTenantIds->count(),
            ];
        }

        return [
            'status' => 'unprovable',
            'reason' => $unprovableReason,
            'rail' => $rail,
        ];
    }

    /**
     * @param array<int,int> $tenantIds
     * @return array<int,int>
     */
    protected function appendTenantId(array $tenantIds, int $tenantId): array
    {
        if ($tenantId <= 0) {
            return $tenantIds;
        }

        if (! in_array($tenantId, $tenantIds, true)) {
            $tenantIds[] = $tenantId;
        }

        return $tenantIds;
    }

    protected function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
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
}

