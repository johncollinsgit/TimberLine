<?php

namespace App\Console\Commands;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\OilAbbreviation;
use App\Models\Scent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;

class MasterDataImport extends Command
{
    protected $signature = 'master-data:import {--zip= : Absolute path to the normalized export zip} {--upsert : Update existing rows instead of skipping them}';

    protected $description = 'Import normalized master data CSVs from a zip export.';

    /** @var array<int,string> */
    protected array $warnings = [];

    public function handle(): int
    {
        $zipPath = (string) $this->option('zip');
        if ($zipPath === '') {
            $this->error('Missing required option: --zip=/absolute/path/to/export.zip');

            return self::FAILURE;
        }

        if (! is_file($zipPath)) {
            $this->error("Zip file not found: {$zipPath}");

            return self::FAILURE;
        }

        if (! class_exists(ZipArchive::class)) {
            $this->error('ZipArchive is not available in this PHP build.');

            return self::FAILURE;
        }

        $tempDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'master-data-import-'.Str::uuid();
        File::ensureDirectoryExists($tempDirectory);

        try {
            $files = $this->extractZip($zipPath, $tempDirectory);
            if ($files === []) {
                $this->warn('No CSV files were found in the zip.');

                return self::SUCCESS;
            }

            $knownFiles = $this->knownFiles($files);
            $ignoredFiles = array_values(array_diff(array_keys($files), array_keys($knownFiles)));

            foreach ($ignoredFiles as $ignoredFile) {
                $this->warning("Ignored CSV with no importer mapping: {$ignoredFile}");
            }

            $upsert = (bool) $this->option('upsert');
            $summaries = [];

            if (isset($knownFiles['scents_master.csv'])) {
                $summaries['scents'] = $this->importScents($knownFiles['scents_master.csv'], $upsert);
            }
            if (isset($knownFiles['base_oils.csv'])) {
                $summaries['base_oils'] = $this->importBaseOils($knownFiles['base_oils.csv'], $upsert);
            }
            if (isset($knownFiles['blends.csv'])) {
                $summaries['blends'] = $this->importBlends($knownFiles['blends.csv'], $upsert);
            }
            if (isset($knownFiles['blend_components.csv'])) {
                $summaries['blend_components'] = $this->importBlendComponents($knownFiles['blend_components.csv'], $upsert);
            }
            if (isset($knownFiles['scent_recipes_pour_room.csv'])) {
                $recipesSummary = $this->importPourRoomRecipes($knownFiles['scent_recipes_pour_room.csv'], $upsert);
                $summaries['oil_abbreviations'] = $recipesSummary['oil_abbreviations'];
                $summaries['scents.oil_blend_id'] = $recipesSummary['scent_links'];
            }

            foreach ($summaries as $label => $summary) {
                $this->line(sprintf(
                    '%s: inserted=%d updated=%d skipped=%d',
                    $label,
                    (int) ($summary['inserted'] ?? 0),
                    (int) ($summary['updated'] ?? 0),
                    (int) ($summary['skipped'] ?? 0)
                ));
            }

            foreach ($this->warnings as $warning) {
                $this->warn($warning);
            }
        } finally {
            File::deleteDirectory($tempDirectory);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>
     */
    protected function extractZip(string $zipPath, string $tempDirectory): array
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new \RuntimeException("Could not open zip: {$zipPath}");
        }

        $zip->extractTo($tempDirectory);
        $zip->close();

        $files = [];

        foreach (File::allFiles($tempDirectory) as $file) {
            $relative = str_replace($tempDirectory.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace('\\', '/', $relative);
            $basename = basename($relative);

            if (! str_ends_with(strtolower($basename), '.csv')) {
                continue;
            }

            if (str_starts_with($relative, '__MACOSX/') || str_starts_with($basename, '._')) {
                continue;
            }

            $files[$basename] = $file->getPathname();
        }

        return $files;
    }

    /**
     * @param  array<string,string>  $files
     * @return array<string,string>
     */
    protected function knownFiles(array $files): array
    {
        $supported = [
            'scents_master.csv',
            'base_oils.csv',
            'blends.csv',
            'blend_components.csv',
            'scent_recipes_pour_room.csv',
        ];

        return array_filter(
            $files,
            fn (string $path, string $name): bool => in_array($name, $supported, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importScents(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path, ['scent_name'], [
            'scent_name', 'status', 'abbreviation', 'oil_list', 'essential_oils', 'not_lotion_safe',
            'descriptions', 'date_discontinued', 'additional_notes', 'scent_name_norm',
        ]);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $name = $this->normalizeText((string) ($row['scent_name'] ?? ''));
            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            $scent = Scent::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first();

            $payload = [
                'name' => $name,
                'display_name' => $name,
                'abbreviation' => $this->normalizeNullable((string) ($row['abbreviation'] ?? '')),
                'oil_reference_name' => $this->normalizeNullable((string) ($row['oil_list'] ?? '')),
                'is_active' => strtolower((string) ($row['status'] ?? 'active')) !== 'discontinued',
            ];

            if (! $scent) {
                Scent::query()->create($payload);
                $summary['inserted']++;
                continue;
            }

            if (! $upsert) {
                $summary['skipped']++;
                continue;
            }

            $scent->fill($payload);
            if (! $scent->isDirty()) {
                $summary['skipped']++;
                continue;
            }

            $scent->save();
            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importBaseOils(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path, ['name'], ['name']);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $name = $this->normalizeText((string) ($row['name'] ?? ''));
            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            $oil = BaseOil::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            $payload = ['name' => $name, 'active' => true];

            if (! $oil) {
                BaseOil::query()->create($payload);
                $summary['inserted']++;
                continue;
            }

            if (! $upsert) {
                $summary['skipped']++;
                continue;
            }

            $oil->fill($payload);
            if (! $oil->isDirty()) {
                $summary['skipped']++;
                continue;
            }

            $oil->save();
            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importBlends(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path, ['blend_name'], ['blend_name', 'blend_abbreviation']);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $name = $this->normalizeText((string) ($row['blend_name'] ?? ''));
            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            $blend = Blend::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            $payload = ['name' => $name, 'is_blend' => true];

            if (! $blend) {
                Blend::query()->create($payload);
                $summary['inserted']++;
                continue;
            }

            if (! $upsert) {
                $summary['skipped']++;
                continue;
            }

            $blend->fill($payload);
            if (! $blend->isDirty()) {
                $summary['skipped']++;
                continue;
            }

            $blend->save();
            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importBlendComponents(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path, ['blend_name', 'base_oil_name', 'ratio_weight'], [
            'blend_name', 'blend_abbreviation', 'base_oil_name', 'ratio_weight',
        ]);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $blendName = $this->normalizeText((string) ($row['blend_name'] ?? ''));
            $baseOilName = $this->normalizeText((string) ($row['base_oil_name'] ?? ''));
            $ratioWeight = max(1, (int) round((float) ($row['ratio_weight'] ?? 0)));

            if ($blendName === '' || $baseOilName === '') {
                $summary['skipped']++;
                continue;
            }

            $blend = Blend::query()->whereRaw('lower(name) = ?', [mb_strtolower($blendName)])->first();
            $baseOil = BaseOil::query()->whereRaw('lower(name) = ?', [mb_strtolower($baseOilName)])->first();

            if (! $blend || ! $baseOil) {
                $summary['skipped']++;
                $this->warnings[] = "Missing FK for blend_components: blend='{$blendName}' base_oil='{$baseOilName}'";
                continue;
            }

            $component = BlendComponent::query()
                ->where('blend_id', $blend->id)
                ->where('base_oil_id', $baseOil->id)
                ->first();

            if (! $component) {
                BlendComponent::query()->create([
                    'blend_id' => $blend->id,
                    'base_oil_id' => $baseOil->id,
                    'ratio_weight' => $ratioWeight,
                ]);
                $summary['inserted']++;
                continue;
            }

            if (! $upsert) {
                $summary['skipped']++;
                continue;
            }

            if ((int) $component->ratio_weight === $ratioWeight) {
                $summary['skipped']++;
                continue;
            }

            $component->ratio_weight = $ratioWeight;
            $component->save();
            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @return array{
     *   oil_abbreviations: array{inserted:int,updated:int,skipped:int},
     *   scent_links: array{inserted:int,updated:int,skipped:int}
     * }
     */
    protected function importPourRoomRecipes(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path, ['Scent Name', 'Oil Name'], [
            'Scent Name', 'Oil Name', 'Abbreviations',
        ]);
        $oilSummary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $linkSummary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $supportsOilBlendColumn = Schema::hasColumn('scents', 'oil_blend_id');

        foreach ($rows as $row) {
            $oilName = $this->normalizeText((string) ($row['Oil Name'] ?? ''));
            $abbreviation = $this->normalizeNullable((string) ($row['Abbreviations'] ?? ''));
            $scentName = $this->normalizeText((string) ($row['Scent Name'] ?? ''));

            if ($oilName !== '') {
                $oil = OilAbbreviation::query()->whereRaw('lower(name) = ?', [mb_strtolower($oilName)])->first();
                $payload = [
                    'name' => $oilName,
                    'abbreviation' => $abbreviation,
                    'is_active' => true,
                ];

                if (! $oil) {
                    OilAbbreviation::query()->create($payload);
                    $oilSummary['inserted']++;
                } elseif ($upsert) {
                    $oil->fill($payload);
                    if ($oil->isDirty()) {
                        $oil->save();
                        $oilSummary['updated']++;
                    } else {
                        $oilSummary['skipped']++;
                    }
                } else {
                    $oilSummary['skipped']++;
                }
            } else {
                $oilSummary['skipped']++;
            }

            if (! $supportsOilBlendColumn || $oilName === '' || $scentName === '') {
                $linkSummary['skipped']++;
                continue;
            }

            $blendName = $this->blendNameFromRecipeOil($oilName);
            if ($blendName === null) {
                $linkSummary['skipped']++;
                continue;
            }

            $blend = Blend::query()->whereRaw('lower(name) = ?', [mb_strtolower($blendName)])->first();
            $scent = Scent::query()->whereRaw('lower(name) = ?', [mb_strtolower($scentName)])->first();

            if (! $blend || ! $scent) {
                $linkSummary['skipped']++;
                $this->warnings[] = "Missing FK for scent recipe link: scent='{$scentName}' oil='{$oilName}'";
                continue;
            }

            if ((int) ($scent->oil_blend_id ?? 0) === (int) $blend->id) {
                $linkSummary['skipped']++;
                continue;
            }

            if (! $upsert && $scent->oil_blend_id) {
                $linkSummary['skipped']++;
                continue;
            }

            $scent->oil_blend_id = $blend->id;
            $scent->save();
            $linkSummary[$scent->wasChanged('oil_blend_id') ? 'updated' : 'skipped']++;
        }

        return [
            'oil_abbreviations' => $oilSummary,
            'scent_links' => $linkSummary,
        ];
    }

    protected function blendNameFromRecipeOil(string $oilName): ?string
    {
        $oilName = $this->normalizeText($oilName);
        if ($oilName === '') {
            return null;
        }

        if (str_ends_with(mb_strtolower($oilName), ' blend')) {
            return $this->normalizeText(mb_substr($oilName, 0, -6));
        }

        return null;
    }

    /**
     * @param  array<int,string>  $requiredHeaders
     * @param  array<int,string>  $knownHeaders
     * @return array<int,array<string,string>>
     */
    protected function readCsv(string $path, array $requiredHeaders = [], array $knownHeaders = []): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException("Could not open CSV: {$path}");
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                return [];
            }

            $headers = array_map(
                fn ($header): string => is_string($header) ? trim($header) : '',
                $headers
            );

            foreach ($requiredHeaders as $requiredHeader) {
                if (! in_array($requiredHeader, $headers, true)) {
                    throw new \RuntimeException("Missing required header '{$requiredHeader}' in {$path}");
                }
            }

            if ($knownHeaders !== []) {
                $ignoredHeaders = array_values(array_diff($headers, $knownHeaders));

                foreach ($ignoredHeaders as $ignoredHeader) {
                    $this->warnings[] = "Ignored CSV field '{$ignoredHeader}' in ".basename($path);
                }
            }

            $rows = [];

            while (($line = fgetcsv($handle)) !== false) {
                if (! is_array($line)) {
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = isset($line[$index]) && is_string($line[$index]) ? trim($line[$index]) : '';
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? $value : '';
    }

    protected function normalizeNullable(string $value): ?string
    {
        $value = $this->normalizeText($value);

        return $value !== '' ? $value : null;
    }
}
