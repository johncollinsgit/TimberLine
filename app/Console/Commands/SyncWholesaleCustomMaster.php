<?php

namespace App\Console\Commands;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncWholesaleCustomMaster extends Command
{
    protected $signature = 'wholesale-custom:sync-master
        {csv : Absolute path to the wholesale custom master CSV}
        {--replace : Replace wholesale_custom_scents with CSV rows before importing}
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

        $summary = [
            'rows_read' => count($rows),
            'rows_skipped' => 0,
            'wholesale_inserted' => 0,
            'wholesale_updated' => 0,
            'wholesale_deleted' => 0,
            'scents_inserted' => 0,
            'scents_updated' => 0,
            'blends_inserted' => 0,
            'blends_updated' => 0,
            'components_written' => 0,
        ];

        $runner = function () use ($rows, $replace, $dryRun, &$summary): void {
            if ($replace) {
                $summary['wholesale_deleted'] = WholesaleCustomScent::query()->count();
                if (! $dryRun) {
                    WholesaleCustomScent::query()->delete();
                }
            }

            $blendIndex = Blend::query()
                ->with(['components.baseOil'])
                ->get()
                ->keyBy(fn (Blend $blend): string => $this->blendLookupKey((string) $blend->name));

            $scents = Scent::query()
                ->get(['id', 'name', 'display_name', 'abbreviation'])
                ->all();

            foreach ($rows as $row) {
                $accountName = $this->normalizeText((string) ($row['Wholesale Account Name'] ?? ''));
                $rawScentName = $this->normalizeText((string) ($row['Scent Name'] ?? ''));
                if ($accountName === '' || $rawScentName === '') {
                    $summary['rows_skipped']++;
                    continue;
                }

                [$customScentName, $parsedAbbreviation] = $this->extractNameAndAbbreviation($rawScentName);
                $explicitAbbreviation = $this->normalizeText((string) ($row['Abbreviation'] ?? ''));
                $abbreviation = $explicitAbbreviation !== '' ? $explicitAbbreviation : $parsedAbbreviation;

                $oils = $this->extractOilRows($row);
                if ($oils === []) {
                    $summary['rows_skipped']++;
                    continue;
                }

                $components = $this->collapseComponents($this->expandOilComponents($oils, $blendIndex));
                if ($components === []) {
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

                foreach ($components as $component) {
                    $baseOil = BaseOil::query()->firstOrCreate(
                        ['name' => $component['name']],
                        ['active' => true]
                    );

                    if (! $dryRun) {
                        BlendComponent::query()->create([
                            'blend_id' => $blend->id,
                            'base_oil_id' => $baseOil->id,
                            'ratio_weight' => (int) $component['weight'],
                        ]);
                    }

                    $summary['components_written']++;
                }

                if (! $dryRun) {
                    $blend->load('components.baseOil');
                    $blendIndex[$this->blendLookupKey((string) $blend->name)] = $blend;
                }

                $canonicalScent = $this->findMatchingScent($scents, $customScentName, $abbreviation);
                if (! $canonicalScent) {
                    $canonicalScent = new Scent();
                    $canonicalScent->name = Scent::normalizeName($customScentName);
                    $canonicalScent->display_name = $customScentName;
                    $canonicalScent->abbreviation = $abbreviation !== '' ? $abbreviation : null;
                    $canonicalScent->oil_reference_name = $customScentName;
                    $canonicalScent->is_blend = true;
                    $canonicalScent->oil_blend_id = $dryRun ? null : $blend->id;
                    $canonicalScent->blend_oil_count = count($components);
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
                    $canonicalScent->blend_oil_count = count($components);
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

                $mapping = WholesaleCustomScent::query()->firstOrNew([
                    'account_name' => $accountName,
                    'custom_scent_name' => $customScentName,
                ]);

                $isNewMapping = ! $mapping->exists;
                $mapping->canonical_scent_id = $canonicalScent->id;
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
        $this->line("rows_read={$summary['rows_read']} rows_skipped={$summary['rows_skipped']}");
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

                // Normalize header variance from exports like "Abbreviation ".
                if (! array_key_exists('Abbreviation', $row)) {
                    foreach ($row as $key => $value) {
                        if (str_starts_with($key, 'Abbreviation')) {
                            $row['Abbreviation'] = $value;
                            break;
                        }
                    }
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
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
     * @param  array<string,string>  $row
     * @return array<int,array{name:string,weight:int}>
     */
    protected function extractOilRows(array $row): array
    {
        $rawOilValues = [
            (string) ($row['Oil #1'] ?? ''),
            (string) ($row['Oil #2'] ?? ''),
            (string) ($row['Oil #3'] ?? ''),
        ];

        $oils = [];
        foreach ($rawOilValues as $rawOil) {
            $rawOil = trim($rawOil);
            if ($rawOil === '') {
                continue;
            }

            $name = $rawOil;
            $weight = 1;

            if (preg_match('/^(.*\D)\s+(\d+)$/u', $rawOil, $matches) === 1) {
                $name = trim((string) $matches[1]);
                $weight = max(1, (int) $matches[2]);
            } elseif (preg_match('/^(.*)\((\d+)\)$/u', $rawOil, $matches) === 1) {
                $name = trim((string) $matches[1]);
                $weight = max(1, (int) $matches[2]);
            }

            if ($name !== '') {
                $oils[] = ['name' => $name, 'weight' => $weight];
            }
        }

        return $oils;
    }

    /**
     * @param  array<int,array{name:string,weight:int}>  $oils
     * @param  \Illuminate\Support\Collection<string,Blend>  $blendIndex
     * @return array<int,array{name:string,weight:int}>
     */
    protected function expandOilComponents(array $oils, $blendIndex): array
    {
        $components = [];

        foreach ($oils as $oil) {
            $oilName = trim((string) ($oil['name'] ?? ''));
            $weight = max(1, (int) ($oil['weight'] ?? 1));
            if ($oilName === '') {
                continue;
            }

            $blend = $blendIndex[$this->blendLookupKey($oilName)] ?? null;
            if ($blend && $blend->components->isNotEmpty()) {
                foreach ($blend->components as $component) {
                    $baseOilName = trim((string) ($component->baseOil?->name ?? ''));
                    if ($baseOilName === '') {
                        continue;
                    }

                    $components[] = [
                        'name' => $baseOilName,
                        'weight' => max(1, (int) $component->ratio_weight) * $weight,
                    ];
                }
                continue;
            }

            $components[] = [
                'name' => $oilName,
                'weight' => $weight,
            ];
        }

        return $components;
    }

    /**
     * @param  array<int,array{name:string,weight:int}>  $components
     * @return array<int,array{name:string,weight:int}>
     */
    protected function collapseComponents(array $components): array
    {
        $collapsed = [];
        foreach ($components as $component) {
            $name = trim((string) ($component['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (! isset($collapsed[$key])) {
                $collapsed[$key] = ['name' => $name, 'weight' => 0];
            }
            $collapsed[$key]['weight'] += max(1, (int) ($component['weight'] ?? 1));
        }

        return array_values($collapsed);
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

    protected function blendLookupKey(string $value): string
    {
        $normalized = Scent::normalizeName($value);
        $normalized = preg_replace('/\s+blend$/u', '', $normalized) ?? $normalized;
        return trim($normalized);
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return is_string($value) ? $value : '';
    }
}

