<?php

namespace App\Console\Commands;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use App\Services\Recipes\NestedOilRecipeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncWholesaleCustomMaster extends Command
{
    protected $signature = 'wholesale-custom:sync-master
        {csv : Absolute path to the wholesale custom master CSV}
        {--replace : Replace wholesale_custom_scents with CSV rows before importing}
        {--allow-create-canonical : Allow creating/updating canonical scent rows during sync}
        {--dry-run : Parse and summarize only; do not write changes}';

    protected $description = 'Sync wholesale custom scent mappings and blend recipes from the master CSV.';

    public function handle(): int
    {
        $csvPath = (string) $this->argument('csv');
        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");
            return self::FAILURE;
        }

        $rows = $this->readMasterCsv($csvPath);
        if ($rows === []) {
            $this->error('No valid rows were found in the CSV.');
            return self::FAILURE;
        }

        $replace = (bool) $this->option('replace');
        $dryRun = (bool) $this->option('dry-run');
        $allowCreateCanonical = (bool) $this->option('allow-create-canonical');

        $summary = [
            'rows_read' => count($rows),
            'rows_skipped' => 0,
            'rows_with_recipe_warnings' => 0,
            'rows_without_canonical_match' => 0,
            'wholesale_inserted' => 0,
            'wholesale_updated' => 0,
            'wholesale_deleted' => 0,
            'scents_inserted' => 0,
            'scents_updated' => 0,
            'blends_inserted' => 0,
            'blends_updated' => 0,
            'components_written' => 0,
        ];

        $resolver = app(NestedOilRecipeResolver::class);
        $preparedRows = $this->prepareRows($rows, $resolver, $summary);
        if ($preparedRows === []) {
            $this->error('No mappable rows were found in the CSV after parsing.');
            return self::FAILURE;
        }

        $recipeDefinitions = [];
        foreach ($preparedRows as $preparedRow) {
            $lookupKey = $resolver->lookupKey((string) ($preparedRow['custom_scent_name'] ?? ''));
            if ($lookupKey === '' || isset($recipeDefinitions[$lookupKey])) {
                continue;
            }

            $recipeDefinitions[$lookupKey] = $preparedRow['top_level_components'] ?? [];
        }

        $runner = function () use ($preparedRows, $recipeDefinitions, $resolver, $replace, $dryRun, $allowCreateCanonical, &$summary): void {
            if ($replace) {
                $summary['wholesale_deleted'] = WholesaleCustomScent::query()->count();
                if (! $dryRun) {
                    WholesaleCustomScent::query()->delete();
                }
            }

            $scents = Scent::query()
                ->get(['id', 'name', 'display_name', 'abbreviation'])
                ->all();

            foreach ($preparedRows as $row) {
                $customScentName = (string) ($row['custom_scent_name'] ?? '');
                $abbreviation = (string) ($row['abbreviation'] ?? '');
                $accountName = (string) ($row['account_name'] ?? '');
                $notes = (string) ($row['notes'] ?? '');

                $resolved = $resolver->resolveToBaseOils(
                    $row['top_level_components'] ?? [],
                    $recipeDefinitions
                );

                if (! empty($resolved['errors'])) {
                    $summary['rows_with_recipe_warnings']++;
                }

                $resolvedComponents = $this->normalizeRatioWeights($resolved['components'] ?? []);
                if ($resolvedComponents === []) {
                    $summary['rows_skipped']++;
                    continue;
                }

                $blend = Blend::query()->firstOrNew(['name' => $customScentName]);
                $isNewBlend = ! $blend->exists;
                $blend->is_blend = true;

                if (! $dryRun) {
                    $blend->save();
                }

                if ($isNewBlend) {
                    $summary['blends_inserted']++;
                } else {
                    $summary['blends_updated']++;
                }

                if (! $dryRun) {
                    $blend->components()->delete();
                }

                foreach ($resolvedComponents as $component) {
                    $baseOil = BaseOil::query()->firstOrCreate(
                        ['name' => (string) $component['name']],
                        ['active' => true]
                    );

                    if (! $dryRun) {
                        BlendComponent::query()->create([
                            'blend_id' => $blend->id,
                            'base_oil_id' => $baseOil->id,
                            'ratio_weight' => (int) $component['ratio_weight'],
                        ]);
                    }

                    $summary['components_written']++;
                }

                $canonicalScent = $this->findMatchingScent($scents, $customScentName, $abbreviation);
                if ($allowCreateCanonical) {
                    if (! $canonicalScent) {
                        $canonicalScent = new Scent();
                        $canonicalScent->name = Scent::normalizeName($customScentName);
                        $canonicalScent->display_name = $customScentName;
                        $canonicalScent->abbreviation = $abbreviation !== '' ? $abbreviation : null;
                        $canonicalScent->oil_reference_name = $customScentName;
                        $canonicalScent->is_blend = true;
                        $canonicalScent->oil_blend_id = $dryRun ? null : $blend->id;
                        $canonicalScent->blend_oil_count = count($resolvedComponents);
                        $canonicalScent->is_wholesale_custom = true;
                        $canonicalScent->is_active = true;

                        if (! $dryRun) {
                            $canonicalScent->save();
                            $canonicalScent->oil_blend_id = $blend->id;
                            $canonicalScent->save();
                        }

                        $scents[] = $canonicalScent;
                        $summary['scents_inserted']++;
                    } else {
                        $canonicalScent->display_name = $customScentName;
                        if ($abbreviation !== '') {
                            $canonicalScent->abbreviation = $abbreviation;
                        }
                        $canonicalScent->oil_reference_name = $customScentName;
                        $canonicalScent->is_blend = true;
                        $canonicalScent->blend_oil_count = count($resolvedComponents);
                        $canonicalScent->is_wholesale_custom = true;
                        $canonicalScent->is_active = true;
                        if (! $dryRun) {
                            $canonicalScent->oil_blend_id = $blend->id;
                        }

                        if (! $dryRun && $canonicalScent->isDirty()) {
                            $canonicalScent->save();
                            $summary['scents_updated']++;
                        } elseif ($dryRun) {
                            $summary['scents_updated']++;
                        }
                    }
                } elseif (! $canonicalScent) {
                    $summary['rows_without_canonical_match']++;
                }

                $mapping = WholesaleCustomScent::query()->firstOrNew([
                    'account_name' => $accountName,
                    'custom_scent_name' => $customScentName,
                ]);

                $isNewMapping = ! $mapping->exists;
                $mapping->canonical_scent_id = $canonicalScent?->id;
                $mapping->oil_1 = $row['oil_1'] ?: null;
                $mapping->oil_2 = $row['oil_2'] ?: null;
                $mapping->oil_3 = $row['oil_3'] ?: null;
                $mapping->total_oils = $row['total_oils'];
                $mapping->abbreviation = $abbreviation !== '' ? $abbreviation : null;
                $mapping->notes = $notes !== '' ? $notes : null;
                $mapping->top_level_recipe_json = [
                    'version' => 1,
                    'slots' => [
                        'oil_1' => $row['oil_1'] ?: null,
                        'oil_2' => $row['oil_2'] ?: null,
                        'oil_3' => $row['oil_3'] ?: null,
                    ],
                    'components' => array_map(fn (array $component): array => [
                        'name' => (string) ($component['name'] ?? ''),
                        'weight' => (float) ($component['weight'] ?? 0.0),
                    ], $row['top_level_components'] ?? []),
                ];
                $mapping->resolved_recipe_json = [
                    'version' => 1,
                    'components' => array_map(fn (array $component): array => [
                        'name' => (string) ($component['name'] ?? ''),
                        'weight' => (float) ($component['weight'] ?? 0.0),
                        'percent' => (float) ($component['percent'] ?? 0.0),
                    ], $resolved['components'] ?? []),
                    'warnings' => array_values(array_unique(array_filter(array_map('strval', array_merge(
                        $resolved['errors'] ?? [],
                        (! $allowCreateCanonical && ! $canonicalScent) ? ['No canonical scent match found. Use New Scent Wizard.'] : []
                    ))))),
                ];
                $mapping->active = true;

                if (! $dryRun) {
                    $mapping->save();
                }

                if ($isNewMapping) {
                    $summary['wholesale_inserted']++;
                } else {
                    $summary['wholesale_updated']++;
                }
            }
        };

        DB::transaction($runner);

        $this->line('Wholesale custom master sync summary:');
        $this->line("rows_read={$summary['rows_read']} rows_skipped={$summary['rows_skipped']} rows_with_recipe_warnings={$summary['rows_with_recipe_warnings']}");
        $this->line("rows_without_canonical_match={$summary['rows_without_canonical_match']} allow_create_canonical=".($allowCreateCanonical ? 'yes' : 'no'));
        $this->line("wholesale_custom_scents: inserted={$summary['wholesale_inserted']} updated={$summary['wholesale_updated']} deleted={$summary['wholesale_deleted']}");
        $this->line("scents: inserted={$summary['scents_inserted']} updated={$summary['scents_updated']}");
        $this->line("blends: inserted={$summary['blends_inserted']} updated={$summary['blends_updated']} components_written={$summary['components_written']}");
        if ($dryRun) {
            $this->warn('Dry run mode: no database writes were persisted.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function readMasterCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return [];
        }

        try {
            $headers = null;
            $rows = [];

            while (($line = fgetcsv($handle)) !== false) {
                if (! is_array($line)) {
                    continue;
                }

                $line = array_map(fn ($value): string => is_string($value) ? trim($value) : '', $line);

                if ($headers === null) {
                    if (in_array('Scent Name', $line, true) && in_array('Wholesale Account Name', $line, true)) {
                        $headers = $line;
                    }
                    continue;
                }

                if (count(array_filter($line, fn (string $value): bool => $value !== '')) === 0) {
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $row[$header] = $line[$index] ?? '';
                }

                $row = $this->normalizeRowHeaders($row);
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string,string>  $row
     * @return array{0:string,1:string}
     */
    protected function extractNameAndAbbreviation(string $value): array
    {
        $name = preg_replace('/\s*\*.*$/u', '', trim($value));
        $name = is_string($name) ? trim($name) : trim($value);
        $abbreviation = '';

        if (preg_match('/^(.*)\(([^)]+)\)\s*$/u', $name, $matches) === 1) {
            $name = trim((string) $matches[1]);
            $abbreviation = trim((string) $matches[2]);
        }

        return [$name, $abbreviation];
    }

    /**
     * @param  array<int,array<string,string>>  $rows
     * @param  array<string,int>  $summary
     * @return array<int,array<string,mixed>>
     */
    protected function prepareRows(array $rows, NestedOilRecipeResolver $resolver, array &$summary): array
    {
        $preparedRows = [];

        foreach ($rows as $row) {
            $accountName = $this->normalizeText((string) ($row['Wholesale Account Name'] ?? ''));
            $rawScentName = $this->normalizeText((string) ($row['Scent Name'] ?? ''));
            if ($accountName === '' || $rawScentName === '') {
                $summary['rows_skipped']++;
                continue;
            }

            [$customScentName, $parsedAbbreviation] = $this->extractNameAndAbbreviation($rawScentName);
            if ($customScentName === '') {
                $summary['rows_skipped']++;
                continue;
            }

            $explicitAbbreviation = $this->normalizeText((string) ($row['Abbreviation'] ?? ''));
            $abbreviation = $explicitAbbreviation !== '' ? $explicitAbbreviation : $parsedAbbreviation;

            $oil1 = $this->normalizeText((string) ($row['Oil #1'] ?? ''));
            $oil2 = $this->normalizeText((string) ($row['Oil #2'] ?? ''));
            $oil3 = $this->normalizeText((string) ($row['Oil #3'] ?? ''));
            $topLevelComponents = $resolver->parseTopLevelComponents([$oil1, $oil2, $oil3]);

            if ($topLevelComponents === []) {
                $summary['rows_skipped']++;
                continue;
            }

            $totalRaw = $this->normalizeText((string) ($row['Total Oils'] ?? ''));
            $totalOils = is_numeric($totalRaw)
                ? max(0, (int) round((float) $totalRaw))
                : count($topLevelComponents);
            $notes = $this->normalizeText((string) ($row['Notes'] ?? ''));

            $preparedRows[] = [
                'account_name' => $accountName,
                'custom_scent_name' => $customScentName,
                'abbreviation' => $abbreviation,
                'oil_1' => $oil1,
                'oil_2' => $oil2,
                'oil_3' => $oil3,
                'total_oils' => $totalOils,
                'notes' => $notes,
                'top_level_components' => $topLevelComponents,
            ];
        }

        return $preparedRows;
    }

    protected function findMatchingScent(array $scents, string $name, string $abbreviation): ?Scent
    {
        $keys = array_values(array_unique(array_filter([
            Scent::normalizeName($name),
            Scent::normalizeName($abbreviation),
        ])));

        foreach ($scents as $scent) {
            foreach ([
                Scent::normalizeName((string) ($scent->name ?? '')),
                Scent::normalizeName((string) ($scent->display_name ?? '')),
                Scent::normalizeName((string) ($scent->abbreviation ?? '')),
            ] as $candidate) {
                if ($candidate !== '' && in_array($candidate, $keys, true)) {
                    return $scent;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{name:string,weight:float,percent:float}>  $components
     * @return array<int,array{name:string,ratio_weight:int,weight:float,percent:float}>
     */
    protected function normalizeRatioWeights(array $components): array
    {
        $scaled = [];

        foreach ($components as $component) {
            $name = trim((string) ($component['name'] ?? ''));
            $weight = (float) ($component['weight'] ?? 0.0);
            if ($name === '' || $weight <= 0) {
                continue;
            }

            $scaled[] = [
                'name' => $name,
                'scaled_weight' => max(1, (int) round($weight * 1000)),
                'weight' => $weight,
                'percent' => (float) ($component['percent'] ?? 0.0),
            ];
        }

        if ($scaled === []) {
            return [];
        }

        $weights = array_map(fn (array $row): int => (int) $row['scaled_weight'], $scaled);
        $gcd = array_shift($weights);
        foreach ($weights as $weight) {
            $gcd = $this->gcd($gcd, $weight);
        }
        $gcd = max(1, (int) $gcd);

        return array_map(function (array $row) use ($gcd): array {
            return [
                'name' => (string) $row['name'],
                'ratio_weight' => max(1, intdiv((int) $row['scaled_weight'], $gcd)),
                'weight' => (float) $row['weight'],
                'percent' => (float) $row['percent'],
            ];
        }, $scaled);
    }

    protected function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        if ($a === 0) {
            return max(1, $b);
        }
        if ($b === 0) {
            return max(1, $a);
        }

        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }

        return max(1, $a);
    }

    /**
     * @param  array<string,string>  $row
     * @return array<string,string>
     */
    protected function normalizeRowHeaders(array $row): array
    {
        if (! array_key_exists('Abbreviation', $row)) {
            foreach ($row as $key => $value) {
                if (str_starts_with($key, 'Abbreviation')) {
                    $row['Abbreviation'] = $value;
                    break;
                }
            }
        }

        if (! array_key_exists('Total Oils', $row)) {
            foreach ($row as $key => $value) {
                if (str_starts_with($key, 'Total Oils')) {
                    $row['Total Oils'] = $value;
                    break;
                }
            }
        }

        if (! array_key_exists('Notes', $row)) {
            foreach ($row as $key => $value) {
                if (mb_strtolower(trim($key)) === 'additional notes') {
                    $row['Notes'] = $value;
                    break;
                }
            }
        }

        return $row;
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return is_string($value) ? $value : '';
    }
}
