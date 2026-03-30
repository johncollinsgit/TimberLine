<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingAuthoringOwnershipRemediationService;
use Illuminate\Console\Command;

class MarketingRemediateAuthoringOwnership extends Command
{
    protected $signature = 'marketing:remediate-authoring-ownership
        {--entity=all : all or comma-separated list: campaigns,segments,templates,mappings,attributions}
        {--apply : Persist deterministically provable tenant ownership assignments}
        {--limit=0 : Optional max unresolved rows to evaluate per entity}
        {--sample=20 : Number of sample row details to print per status bucket}
        {--show-rows : Print sample row details for each classification bucket}';

    protected $description = 'Audit unresolved authoring ownership rails and safely remediate provable tenant ownership while keeping ambiguous rows fail-closed.';

    public function handle(MarketingAuthoringOwnershipRemediationService $service): int
    {
        $entityOption = strtolower(trim((string) $this->option('entity')));
        $entities = $entityOption === '' || $entityOption === 'all'
            ? MarketingAuthoringOwnershipRemediationService::ENTITIES
            : collect(explode(',', $entityOption))
                ->map(fn ($value): string => strtolower(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all();

        $invalid = collect($entities)
            ->filter(fn ($entity): bool => ! in_array($entity, MarketingAuthoringOwnershipRemediationService::ENTITIES, true))
            ->values();
        if ($invalid->isNotEmpty()) {
            $this->error('Invalid --entity option: ' . $invalid->implode(', '));
            $this->line('Allowed: all,' . implode(',', MarketingAuthoringOwnershipRemediationService::ENTITIES));

            return self::FAILURE;
        }

        $limitRaw = (int) $this->option('limit');
        $limitPerEntity = $limitRaw > 0 ? $limitRaw : null;
        $sampleLimit = max(1, min(200, (int) $this->option('sample')));
        $apply = (bool) $this->option('apply');
        $showRows = (bool) $this->option('show-rows');

        $result = $service->run(
            apply: $apply,
            entities: $entities,
            sampleLimit: $sampleLimit,
            limitPerEntity: $limitPerEntity
        );

        $this->line('mode=' . (string) ($result['mode'] ?? ($apply ? 'apply' : 'dry-run')));
        $this->line('entity_count=' . count((array) ($result['entities'] ?? [])));
        $this->line('limit_per_entity=' . ($limitPerEntity ?? 'unbounded'));

        foreach ((array) ($result['entities'] ?? []) as $entity => $summary) {
            $this->line(implode(' ', [
                'entity=' . $entity,
                'table=' . (string) ($summary['table'] ?? ''),
                'rail=' . (string) ($summary['rail'] ?? ''),
                'skipped=' . ((bool) ($summary['skipped'] ?? false) ? '1' : '0'),
                'unresolved_total=' . (int) ($summary['unresolved_total'] ?? 0),
                'evaluated=' . (int) ($summary['evaluated'] ?? 0),
                'provable=' . (int) ($summary['provable'] ?? 0),
                'assigned=' . (int) ($summary['assigned'] ?? 0),
                'ambiguous=' . (int) ($summary['ambiguous'] ?? 0),
                'unprovable=' . (int) ($summary['unprovable'] ?? 0),
                'unsupported=' . (int) ($summary['unsupported'] ?? 0),
                'remaining_unresolved=' . (int) ($summary['remaining_unresolved'] ?? 0),
            ]));

            if ((bool) ($summary['skipped'] ?? false)) {
                $this->line('  reason=' . (string) ($summary['reason'] ?? 'table_or_tenant_column_missing'));
            }

            if (! $showRows) {
                continue;
            }

            foreach (['provable', 'ambiguous', 'unprovable', 'unsupported'] as $bucket) {
                $samples = (array) (($summary['sample'][$bucket] ?? []));
                if ($samples === []) {
                    continue;
                }

                foreach ($samples as $sample) {
                    $payload = collect((array) $sample)
                        ->map(fn ($value, $key): string => $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value)))
                        ->implode(' ');
                    $this->line(sprintf('  sample[%s] %s', $bucket, $payload));
                }
            }
        }

        $totals = (array) ($result['totals'] ?? []);
        $this->line(implode(' ', [
            'totals',
            'unresolved_total=' . (int) ($totals['unresolved_total'] ?? 0),
            'evaluated=' . (int) ($totals['evaluated'] ?? 0),
            'provable=' . (int) ($totals['provable'] ?? 0),
            'assigned=' . (int) ($totals['assigned'] ?? 0),
            'ambiguous=' . (int) ($totals['ambiguous'] ?? 0),
            'unprovable=' . (int) ($totals['unprovable'] ?? 0),
            'unsupported=' . (int) ($totals['unsupported'] ?? 0),
            'remaining_unresolved=' . (int) ($totals['remaining_unresolved'] ?? 0),
        ]));

        return self::SUCCESS;
    }
}

