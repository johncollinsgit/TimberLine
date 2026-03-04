<?php

namespace App\Console\Commands;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\CandleClubScent;
use App\Models\OilAbbreviation;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class MasterDataImport extends Command
{
    protected $signature = 'master-data:import
        {--zip= : Path to the normalized export zip}
        {--dir= : Path to the extracted normalized export directory}
        {--upsert : Update existing rows instead of skipping them}';

    protected $description = 'Import normalized master data CSVs from a zip export or extracted directory.';

    /** @var array<int,string> */
    protected array $warnings = [];

    public function handle(): int
    {
        $this->warnings = [];

        $zipPath = trim((string) $this->option('zip'));
        $directoryPath = trim((string) $this->option('dir'));
        $tempDirectory = null;
        $emptyStateMessage = 'No CSV files were found.';

        if ($zipPath === '' && $directoryPath === '') {
            $this->error('Missing required option: provide either --zip=... or --dir=...');

            return self::FAILURE;
        }

        if ($zipPath !== '' && $directoryPath !== '') {
            $this->error('Use either --zip or --dir, not both.');

            return self::FAILURE;
        }

        if ($zipPath !== '') {
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
            $emptyStateMessage = 'No CSV files were found in the zip.';
        } else {
            if (! is_dir($directoryPath)) {
                $this->error("Import directory not found: {$directoryPath}");

                return self::FAILURE;
            }

            $emptyStateMessage = 'No CSV files were found in the directory.';
        }

        try {
            $files = $zipPath !== ''
                ? $this->extractZip($zipPath, (string) $tempDirectory)
                : $this->discoverCsvFiles($directoryPath);

            if ($files === []) {
                $this->warn($emptyStateMessage);

                return self::SUCCESS;
            }

            $knownFiles = $this->knownFiles($files);
            $ignoredFiles = array_values(array_diff(array_keys($files), array_keys($knownFiles)));

            foreach ($ignoredFiles as $ignoredFile) {
                $this->warn("Ignored CSV with no importer mapping: {$ignoredFile}");
            }

            $upsert = (bool) $this->option('upsert');
            $summaries = [];

            if (isset($knownFiles['scents_master.csv'])) {
                $summaries['scents'] = $this->runImportStep(
                    'scents_master.csv',
                    fn (): array => $this->importScents($knownFiles['scents_master.csv'], $upsert)
                );
            }
            if (isset($knownFiles['collections_long.csv'])) {
                if (Schema::hasTable('collections')) {
                    $summaries['collections'] = $this->runImportStep(
                        'collections_long.csv',
                        fn (): array => $this->importCollections($knownFiles['collections_long.csv'], $upsert)
                    );
                } else {
                    $this->warn('Skipped collections_long.csv because the collections table does not exist.');
                }
            }
            if (isset($knownFiles['seasonal_scents.csv'])) {
                if (Schema::hasTable('seasonal_scents')) {
                    $summaries['seasonal_scents'] = $this->runImportStep(
                        'seasonal_scents.csv',
                        fn (): array => $this->importSeasonalScents($knownFiles['seasonal_scents.csv'], $upsert)
                    );
                } else {
                    $this->warn('Skipped seasonal_scents.csv because the seasonal_scents table does not exist.');
                }
            }
            if (isset($knownFiles['base_oils.csv'])) {
                $summaries['base_oils'] = $this->runImportStep(
                    'base_oils.csv',
                    fn (): array => $this->importBaseOils($knownFiles['base_oils.csv'], $upsert)
                );
            }
            if (isset($knownFiles['blends.csv'])) {
                $summaries['blends'] = $this->runImportStep(
                    'blends.csv',
                    fn (): array => $this->importBlends($knownFiles['blends.csv'], $upsert)
                );
            }
            if (isset($knownFiles['blend_components.csv'])) {
                $summaries['blend_components'] = $this->runImportStep(
                    'blend_components.csv',
                    fn (): array => $this->importBlendComponents($knownFiles['blend_components.csv'], $upsert)
                );
            }
            if (isset($knownFiles['scent_recipes_pour_room.csv'])) {
                $recipesSummary = $this->runImportStep(
                    'scent_recipes_pour_room.csv',
                    fn (): array => $this->importPourRoomRecipes($knownFiles['scent_recipes_pour_room.csv'], $upsert)
                );
                $summaries['oil_abbreviations'] = $recipesSummary['oil_abbreviations'];
                $summaries['scents.oil_blend_id'] = $recipesSummary['scent_links'];
            }
            if (isset($knownFiles['candle_club_scent_recipes.csv'])) {
                $candleClubSummary = $this->runImportStep(
                    'candle_club_scent_recipes.csv',
                    fn (): array => $this->importCandleClubScents($knownFiles['candle_club_scent_recipes.csv'], $upsert)
                );
                $summaries['candle_club_scents'] = $candleClubSummary['links'];
                $summaries['candle_club_scents.scent_rows'] = $candleClubSummary['scents'];
            }
            if (isset($knownFiles['wholesale_custom_scents_sheet.csv'])) {
                $summaries['wholesale_custom_scents.active'] = $this->runImportStep(
                    'wholesale_custom_scents_sheet.csv',
                    fn (): array => $this->importWholesaleCustomScents($knownFiles['wholesale_custom_scents_sheet.csv'], $upsert, true)
                );
            }
            if (isset($knownFiles['retired_wholesale_custom_scents_sheet.csv'])) {
                $summaries['wholesale_custom_scents.retired'] = $this->runImportStep(
                    'retired_wholesale_custom_scents_sheet.csv',
                    fn (): array => $this->importWholesaleCustomScents($knownFiles['retired_wholesale_custom_scents_sheet.csv'], $upsert, false)
                );
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
            if ($tempDirectory !== null) {
                File::deleteDirectory($tempDirectory);
            }
        }

        return self::SUCCESS;
    }

    protected function runImportStep(string $label, callable $callback): mixed
    {
        try {
            return DB::transaction(fn () => $callback());
        } catch (Throwable $e) {
            throw new \RuntimeException("Failed importing {$label}: {$e->getMessage()}", 0, $e);
        }
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

        return $this->discoverCsvFiles($tempDirectory);
    }

    /**
     * @return array<string,string>
     */
    protected function discoverCsvFiles(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace($directory.DIRECTORY_SEPARATOR, '', $file->getPathname());
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
            'collections_long.csv',
            'seasonal_scents.csv',
            'base_oils.csv',
            'blends.csv',
            'blend_components.csv',
            'scent_recipes_pour_room.csv',
            'candle_club_scent_recipes.csv',
            'wholesale_custom_scents_sheet.csv',
            'retired_wholesale_custom_scents_sheet.csv',
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
        $lookup = $this->makeLookupMap(
            Scent::query()->get(['id', 'name', 'display_name', 'abbreviation', 'oil_reference_name', 'is_active']),
            fn (Scent $scent): array => [$scent->name, $scent->display_name]
        );

        foreach ($rows as $row) {
            $name = $this->canonicalizeName((string) ($row['scent_name'] ?? ''));
            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            /** @var Scent|null $scent */
            $scent = $this->lookupByNaturalKeys($lookup, [$name]);

            $payload = [
                'name' => $name,
                'display_name' => $name,
                'abbreviation' => $this->normalizeNullable((string) ($row['abbreviation'] ?? '')),
                'oil_reference_name' => $this->normalizeNullable((string) ($row['oil_list'] ?? '')),
                'is_active' => strtolower((string) ($row['status'] ?? 'active')) !== 'discontinued',
            ];

            if (! $scent) {
                $scent = Scent::query()->create($payload);
                $this->indexModel($lookup, $scent, [$payload['name'], $payload['display_name']]);
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
            $this->indexModel($lookup, $scent, [$payload['name'], $payload['display_name']]);
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
        $lookup = $this->makeLookupMap(
            BaseOil::query()->get(['id', 'name', 'active']),
            fn (BaseOil $oil): array => [$oil->name]
        );

        foreach ($rows as $row) {
            $name = $this->canonicalizeName((string) ($row['name'] ?? ''));
            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            /** @var BaseOil|null $oil */
            $oil = $this->lookupByNaturalKeys($lookup, [$name]);
            $payload = ['name' => $name, 'active' => true];

            if (! $oil) {
                $oil = BaseOil::query()->create($payload);
                $this->indexModel($lookup, $oil, [$payload['name']]);
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
            $this->indexModel($lookup, $oil, [$payload['name']]);
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
        $lookup = $this->makeLookupMap(
            Blend::query()->get(['id', 'name', 'is_blend']),
            fn (Blend $blend): array => [$blend->name]
        );

        foreach ($rows as $row) {
            $name = $this->canonicalizeName((string) ($row['blend_name'] ?? ''));
            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            /** @var Blend|null $blend */
            $blend = $this->lookupByNaturalKeys($lookup, [$name]);
            $payload = ['name' => $name, 'is_blend' => true];

            if (! $blend) {
                $blend = Blend::query()->create($payload);
                $this->indexModel($lookup, $blend, [$payload['name']]);
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
            $this->indexModel($lookup, $blend, [$payload['name']]);
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
        $blendLookup = $this->makeLookupMap(
            Blend::query()->get(['id', 'name']),
            fn (Blend $blend): array => [$blend->name]
        );
        $baseOilLookup = $this->makeLookupMap(
            BaseOil::query()->get(['id', 'name']),
            fn (BaseOil $oil): array => [$oil->name]
        );
        $componentLookup = [];

        foreach (BlendComponent::query()->get(['id', 'blend_id', 'base_oil_id', 'ratio_weight']) as $component) {
            $componentLookup[$this->blendComponentLookupKey((int) $component->blend_id, (int) $component->base_oil_id)] = $component;
        }

        foreach ($rows as $row) {
            $blendName = $this->canonicalizeName((string) ($row['blend_name'] ?? ''));
            $baseOilName = $this->canonicalizeName((string) ($row['base_oil_name'] ?? ''));
            $ratioWeight = max(1, (int) round((float) ($row['ratio_weight'] ?? 0)));

            if ($blendName === '' || $baseOilName === '') {
                $summary['skipped']++;
                continue;
            }

            /** @var Blend|null $blend */
            $blend = $this->lookupByNaturalKeys($blendLookup, [$blendName]);
            /** @var BaseOil|null $baseOil */
            $baseOil = $this->lookupByNaturalKeys($baseOilLookup, [$baseOilName]);

            if (! $blend || ! $baseOil) {
                $summary['skipped']++;
                $this->warnings[] = "Missing FK for blend_components: blend='{$blendName}' base_oil='{$baseOilName}'";
                continue;
            }

            $lookupKey = $this->blendComponentLookupKey((int) $blend->id, (int) $baseOil->id);
            $component = $componentLookup[$lookupKey] ?? null;

            if (! $component) {
                $component = BlendComponent::query()->create([
                    'blend_id' => $blend->id,
                    'base_oil_id' => $baseOil->id,
                    'ratio_weight' => $ratioWeight,
                ]);
                $componentLookup[$lookupKey] = $component;
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
        $oilLookup = $this->makeLookupMap(
            OilAbbreviation::query()->get(['id', 'name', 'abbreviation', 'is_active']),
            fn (OilAbbreviation $oil): array => [$oil->name]
        );
        $blendLookup = $supportsOilBlendColumn
            ? $this->makeLookupMap(
                Blend::query()->get(['id', 'name']),
                fn (Blend $blend): array => [$blend->name]
            )
            : [];
        $scentLookup = $supportsOilBlendColumn
            ? $this->makeLookupMap(
                Scent::query()->get(['id', 'name', 'display_name', 'oil_blend_id']),
                fn (Scent $scent): array => [$scent->name, $scent->display_name]
            )
            : [];

        foreach ($rows as $row) {
            $oilName = $this->canonicalizeName((string) ($row['Oil Name'] ?? ''));
            $abbreviation = $this->normalizeNullable((string) ($row['Abbreviations'] ?? ''));
            $scentName = $this->canonicalizeName((string) ($row['Scent Name'] ?? ''));

            if ($oilName !== '') {
                /** @var OilAbbreviation|null $oil */
                $oil = $this->lookupByNaturalKeys($oilLookup, [$oilName]);
                $payload = [
                    'name' => $oilName,
                    'abbreviation' => $abbreviation,
                    'is_active' => true,
                ];

                if (! $oil) {
                    $oil = OilAbbreviation::query()->create($payload);
                    $this->indexModel($oilLookup, $oil, [$payload['name']]);
                    $oilSummary['inserted']++;
                } elseif ($upsert) {
                    $oil->fill($payload);
                    if ($oil->isDirty()) {
                        $oil->save();
                        $this->indexModel($oilLookup, $oil, [$payload['name']]);
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

            /** @var Blend|null $blend */
            $blend = $this->lookupByNaturalKeys($blendLookup, [$blendName]);
            /** @var Scent|null $scent */
            $scent = $this->lookupByNaturalKeys($scentLookup, [$scentName]);

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

    /**
     * @return array{
     *   links: array{inserted:int,updated:int,skipped:int},
     *   scents: array{inserted:int,updated:int,skipped:int}
     * }
     */
    protected function importCandleClubScents(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path, ['month', 'scent_name'], [
            'month', 'scent_name', 'oil_1', 'oil_2', 'abbreviations', 'additional_notes', 'unnamed_6', 'unnamed_7', 'unnamed_8',
        ]);
        $linkSummary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $scentSummary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $scentLookup = $this->makeLookupMap(
            Scent::query()->get(['id', 'name', 'display_name', 'oil_reference_name', 'is_candle_club', 'is_active']),
            fn (Scent $scent): array => [$scent->name, $scent->display_name]
        );
        $linkLookup = [];

        foreach (CandleClubScent::query()->with('scent:id,name,display_name,oil_reference_name,is_candle_club,is_active')->get(['id', 'month', 'year', 'scent_id']) as $link) {
            $linkLookup[$this->candleClubLookupKey((int) $link->month, (int) $link->year)] = $link;
        }

        foreach ($rows as $row) {
            $rawPeriod = $this->normalizeText((string) ($row['month'] ?? ''));
            $rawScentName = $this->normalizeText((string) ($row['scent_name'] ?? ''));

            if ($rawPeriod === '' || $rawScentName === '') {
                $linkSummary['skipped']++;
                $scentSummary['skipped']++;
                continue;
            }

            $period = $this->parseCandleClubPeriod($rawPeriod);
            if ($period === null) {
                $this->warnings[] = "Skipped candle_club_scent_recipes row with ambiguous month value '{$rawPeriod}'";
                $linkSummary['skipped']++;
                $scentSummary['skipped']++;
                continue;
            }

            $month = $period['month'];
            $year = $period['year'];
            $displayBaseName = $this->normalizeText($this->stripTrailingAnnotations($rawScentName));
            if ($displayBaseName === '') {
                $displayBaseName = $rawScentName;
            }

            $display = \Carbon\Carbon::create()->month($month)->format('F')." {$year} Candle Club — {$displayBaseName}";
            $oilReference = $this->normalizeNullable($this->candleClubOilReference([
                (string) ($row['oil_1'] ?? ''),
                (string) ($row['oil_2'] ?? ''),
            ]));
            $payload = [
                'name' => $display,
                'display_name' => $display,
                'oil_reference_name' => $oilReference,
                'is_candle_club' => true,
                'is_active' => true,
            ];

            $lookupKey = $this->candleClubLookupKey($month, $year);
            /** @var CandleClubScent|null $link */
            $link = $linkLookup[$lookupKey] ?? null;

            if ($link && ! $upsert) {
                $linkSummary['skipped']++;
                $scentSummary['skipped']++;
                continue;
            }

            /** @var Scent|null $scent */
            $scent = $link?->scent;

            if (! $scent) {
                $scent = $this->lookupByNaturalKeys($scentLookup, [$display]);
            }

            if (! $scent) {
                $scent = Scent::query()->create($payload);
                $this->indexModel($scentLookup, $scent, [$payload['name'], $payload['display_name']]);
                $scentSummary['inserted']++;
            } elseif ($upsert) {
                $scent->fill($payload);
                if ($scent->isDirty()) {
                    $scent->save();
                    $this->indexModel($scentLookup, $scent, [$payload['name'], $payload['display_name']]);
                    $scentSummary['updated']++;
                } else {
                    $scentSummary['skipped']++;
                }
            } else {
                $scentSummary['skipped']++;
            }

            if (! $link) {
                $link = CandleClubScent::query()->create([
                    'month' => $month,
                    'year' => $year,
                    'scent_id' => $scent->id,
                ]);
                $link->setRelation('scent', $scent);
                $linkLookup[$lookupKey] = $link;
                $linkSummary['inserted']++;
                continue;
            }

            if ((int) $link->scent_id === (int) $scent->id) {
                $linkSummary['skipped']++;
                continue;
            }

            $link->scent_id = $scent->id;
            $link->save();
            $link->setRelation('scent', $scent);
            $linkSummary['updated']++;
        }

        return [
            'links' => $linkSummary,
            'scents' => $scentSummary,
        ];
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importWholesaleCustomScents(string $path, bool $upsert, bool $active): array
    {
        $rows = $this->readCsv($path, ['Scent Name', 'Wholesale Account Name'], [
            'Scent Name', 'Oil #1', 'Oil #2', 'Oil #3', 'Total Oils', 'Abbreviation', 'Wholesale Account Name', 'Notes',
        ]);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $lookup = [];
        $scentLookup = $this->makeLookupMap(
            Scent::query()->get(['id', 'name', 'display_name', 'abbreviation']),
            fn (Scent $scent): array => [$scent->name, $scent->display_name, $scent->abbreviation]
        );

        foreach (WholesaleCustomScent::query()->get(['id', 'account_name', 'custom_scent_name', 'canonical_scent_id', 'notes', 'active']) as $record) {
            $this->indexWholesaleCustomScent($lookup, $record);
        }

        foreach ($rows as $row) {
            $accountName = $this->normalizeText((string) ($row['Wholesale Account Name'] ?? ''));
            $customScentName = $this->normalizeText((string) ($row['Scent Name'] ?? ''));

            if ($accountName === '' || $customScentName === '') {
                $summary['skipped']++;
                continue;
            }

            /** @var WholesaleCustomScent|null $record */
            $record = $this->lookupWholesaleCustomScent($lookup, $accountName, $customScentName);
            /** @var Scent|null $canonical */
            $canonical = $this->lookupByNaturalKeys($scentLookup, $this->extractCustomScentMatchCandidates($row));

            if (! $canonical) {
                $this->warnings[] = "No canonical scent match for wholesale_custom_scents: account='{$accountName}' scent='{$customScentName}'";
            }

            $notesProvided = array_key_exists('Notes', $row);
            $notes = $notesProvided ? $this->normalizeNullable((string) ($row['Notes'] ?? '')) : null;

            $createPayload = [
                'account_name' => $accountName,
                'custom_scent_name' => $customScentName,
                'canonical_scent_id' => $canonical?->id,
                'active' => $active,
            ];

            if ($notesProvided) {
                $createPayload['notes'] = $notes;
            }

            if (! $record) {
                $record = WholesaleCustomScent::query()->create($createPayload);
                $this->indexWholesaleCustomScent($lookup, $record);
                $summary['inserted']++;
                continue;
            }

            if (! $upsert) {
                $summary['skipped']++;
                continue;
            }

            $updatePayload = [
                'account_name' => $accountName,
                'custom_scent_name' => $customScentName,
                'active' => $active,
            ];

            if ($canonical) {
                $updatePayload['canonical_scent_id'] = $canonical->id;
            }

            if ($notesProvided) {
                $updatePayload['notes'] = $notes;
            }

            $record->fill($updatePayload);
            if (! $record->isDirty()) {
                $summary['skipped']++;
                continue;
            }

            $record->save();
            $this->indexWholesaleCustomScent($lookup, $record);
            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importCollections(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $lookup = [];

        foreach (DB::table('collections')->get(['id', 'name']) as $collection) {
            $this->indexNaturalLookupValue($lookup, (int) $collection->id, [(string) $collection->name]);
        }

        foreach ($rows as $row) {
            $name = $this->collectionNameFromRow($row);

            if ($name === '') {
                $summary['skipped']++;
                continue;
            }

            if ($this->lookupNaturalLookupValue($lookup, [$name]) !== null) {
                $summary['skipped']++;
                continue;
            }

            $collectionId = (int) DB::table('collections')->insertGetId([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->indexNaturalLookupValue($lookup, $collectionId, [$name]);
            $summary['inserted']++;
        }

        return $summary;
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int}
     */
    protected function importSeasonalScents(string $path, bool $upsert): array
    {
        $rows = $this->readCsv($path);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $scentLookup = $this->makeLookupMap(
            Scent::query()->get(['id', 'name', 'display_name', 'abbreviation']),
            fn (Scent $scent): array => [$scent->name, $scent->display_name, $scent->abbreviation]
        );
        $existing = [];

        foreach (DB::table('seasonal_scents')->get(['scent_id', 'season']) as $row) {
            $existing[$this->seasonalScentLookupKey((int) $row->scent_id, (string) $row->season)] = true;
        }

        foreach ($rows as $row) {
            $scentName = $this->canonicalizeName($this->firstMatchingValue($row, [
                'scent_name', 'Scent Name', 'scent', 'Scent', 'name', 'Name',
            ]));
            $season = $this->normalizeSeasonValue($this->firstMatchingValue($row, [
                'season', 'Season',
            ]));

            if ($scentName === '' || $season === '') {
                $summary['skipped']++;
                continue;
            }

            /** @var Scent|null $scent */
            $scent = $this->lookupByNaturalKeys($scentLookup, [$scentName]);

            if (! $scent) {
                $summary['skipped']++;
                $this->warnings[] = "Missing FK for seasonal_scents: scent='{$scentName}' season='{$season}'";
                continue;
            }

            $lookupKey = $this->seasonalScentLookupKey((int) $scent->id, $season);

            if (isset($existing[$lookupKey])) {
                $summary['skipped']++;
                continue;
            }

            DB::table('seasonal_scents')->insert([
                'scent_id' => $scent->id,
                'season' => $season,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing[$lookupKey] = true;
            $summary['inserted']++;
        }

        return $summary;
    }

    /**
     * @param  iterable<int,Model>  $models
     * @return array<string,Model>
     */
    protected function makeLookupMap(iterable $models, callable $resolver): array
    {
        $lookup = [];

        foreach ($models as $model) {
            $this->indexModel($lookup, $model, $resolver($model));
        }

        return $lookup;
    }

    /**
     * @param  array<string,Model>  $lookup
     * @param  array<int,string|null>  $values
     */
    protected function lookupByNaturalKeys(array $lookup, array $values): ?Model
    {
        foreach ($values as $value) {
            foreach ($this->candidateNaturalKeys((string) ($value ?? '')) as $key) {
                if (isset($lookup[$key])) {
                    return $lookup[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $lookup
     * @param  array<int,string|null>  $values
     */
    protected function lookupNaturalLookupValue(array $lookup, array $values): mixed
    {
        foreach ($values as $value) {
            foreach ($this->candidateNaturalKeys((string) ($value ?? '')) as $key) {
                if (array_key_exists($key, $lookup)) {
                    return $lookup[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,Model>  $lookup
     * @param  array<int,string|null>  $values
     */
    protected function indexModel(array &$lookup, Model $model, array $values): void
    {
        foreach ($values as $value) {
            foreach ($this->candidateNaturalKeys((string) ($value ?? '')) as $key) {
                $lookup[$key] ??= $model;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $lookup
     * @param  array<int,string|null>  $values
     */
    protected function indexNaturalLookupValue(array &$lookup, mixed $value, array $values): void
    {
        foreach ($values as $candidate) {
            foreach ($this->candidateNaturalKeys((string) ($candidate ?? '')) as $key) {
                $lookup[$key] ??= $value;
            }
        }
    }

    /**
     * @return array<int,string>
     */
    protected function candidateNaturalKeys(string $value): array
    {
        $keys = [];

        foreach ($this->naturalKeyVariants($value) as $variant) {
            $normalized = $this->normalizeMatchValue($variant);
            if ($normalized === '') {
                continue;
            }

            $keys[] = 'match:'.$normalized;
            $slug = Str::slug($normalized);

            if ($slug !== '') {
                $keys[] = 'slug:'.$slug;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int,string>
     */
    protected function naturalKeyVariants(string $value): array
    {
        $base = $this->canonicalizeName($value);
        if ($base === '') {
            return [];
        }

        $variants = [$base];
        $stripped = $this->canonicalizeName($this->stripTrailingAnnotations($base));

        if ($stripped !== $base) {
            $variants[] = $stripped;
        }

        foreach ($variants as $variant) {
            $punctuationStripped = preg_replace('/[^\pL\pN\s]+/u', ' ', $variant);
            $punctuationStripped = $this->canonicalizeName(is_string($punctuationStripped) ? $punctuationStripped : '');

            if ($punctuationStripped !== '' && $punctuationStripped !== $variant) {
                $variants[] = $punctuationStripped;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    protected function normalizeMatchValue(string $value): string
    {
        $value = $this->normalizeText($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[-_]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim(mb_strtolower(is_string($value) ? $value : ''));
    }

    protected function canonicalizeName(string $value): string
    {
        $value = $this->normalizeText($value);

        if ($value === '') {
            return '';
        }

        $aliases = [
            $this->normalizeMatchValue('Orange Sanalwood') => 'Orange Sandalwood',
            $this->normalizeMatchValue('Orange Sanalwood Blend') => 'Orange Sandalwood Blend',
            $this->normalizeMatchValue('Pumpin Chai') => 'Pumpkin Chai',
        ];

        return $aliases[$this->normalizeMatchValue($value)] ?? $value;
    }

    protected function stripTrailingAnnotations(string $value): string
    {
        $value = $this->normalizeText($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s*\*.*$/u', '', $value);
        $value = is_string($value) ? $value : '';

        do {
            $updated = preg_replace('/\s*\([^)]*\)\s*$/u', '', $value);
            $updated = $this->normalizeText(is_string($updated) ? $updated : '');

            if ($updated === '' || $updated === $value) {
                break;
            }

            $value = $updated;
        } while (true);

        return $this->normalizeText($value);
    }

    protected function blendNameFromRecipeOil(string $oilName): ?string
    {
        $oilName = $this->canonicalizeName($oilName);
        if ($oilName === '') {
            return null;
        }

        if (str_ends_with(mb_strtolower($oilName), ' blend')) {
            return $this->normalizeText(mb_substr($oilName, 0, -6));
        }

        return null;
    }

    protected function blendComponentLookupKey(int $blendId, int $baseOilId): string
    {
        return $blendId.':'.$baseOilId;
    }

    protected function candleClubLookupKey(int $month, int $year): string
    {
        return $year.':'.$month;
    }

    protected function seasonalScentLookupKey(int $scentId, string $season): string
    {
        return $scentId.':'.$this->normalizeMatchValue($season);
    }

    /**
     * @return array{month:int,year:int}|null
     */
    protected function parseCandleClubPeriod(string $value): ?array
    {
        $value = $this->normalizeText($value);
        if ($value === '' || preg_match('/^\d{4}$/', $value) === 1) {
            return null;
        }

        try {
            $parsed = \Carbon\Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return [
            'month' => (int) $parsed->month,
            'year' => (int) $parsed->year,
        ];
    }

    /**
     * @param  array<int,string>  $values
     */
    protected function candleClubOilReference(array $values): string
    {
        return implode(' | ', array_values(array_filter(array_map(
            fn (string $value): string => $this->normalizeText($value),
            $values
        ))));
    }

    /**
     * @param  array<string,string>  $row
     * @return array<int,string>
     */
    protected function extractCustomScentMatchCandidates(array $row): array
    {
        $customName = $this->normalizeText((string) ($row['Scent Name'] ?? ''));
        $stripped = $this->canonicalizeName($this->stripTrailingAnnotations($customName));
        $abbreviation = $this->normalizeText((string) ($row['Abbreviation'] ?? ''));

        return array_values(array_unique(array_filter([
            $this->canonicalizeName($customName),
            $stripped,
            $abbreviation,
        ])));
    }

    /**
     * @param  array<string,WholesaleCustomScent>  $lookup
     */
    protected function lookupWholesaleCustomScent(array $lookup, string $accountName, string $customScentName): ?WholesaleCustomScent
    {
        foreach ($this->wholesaleCustomLookupKeys($accountName, $customScentName) as $key) {
            if (isset($lookup[$key])) {
                return $lookup[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string,WholesaleCustomScent>  $lookup
     */
    protected function indexWholesaleCustomScent(array &$lookup, WholesaleCustomScent $record): void
    {
        foreach ($this->wholesaleCustomLookupKeys($record->account_name, $record->custom_scent_name) as $key) {
            $lookup[$key] ??= $record;
        }
    }

    /**
     * @return array<int,string>
     */
    protected function wholesaleCustomLookupKeys(string $accountName, string $customScentName): array
    {
        $normalizedAccount = WholesaleCustomScent::normalizeAccountName($accountName);
        if ($normalizedAccount === '') {
            return [];
        }

        $keys = [];

        foreach ($this->candidateNaturalKeys($customScentName) as $candidate) {
            $keys[] = $normalizedAccount.'|'.$candidate;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,string>  $row
     * @param  array<int,string>  $keys
     */
    protected function firstMatchingValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return (string) ($row[$key] ?? '');
            }
        }

        foreach ($row as $value) {
            if ($this->normalizeText((string) $value) !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function collectionNameFromRow(array $row): string
    {
        return $this->normalizeText($this->firstMatchingValue($row, [
            'collection_name', 'Collection Name', 'collection', 'Collection', 'name', 'Name',
        ]));
    }

    protected function normalizeSeasonValue(string $value): string
    {
        $value = $this->normalizeText($value);

        if ($value === '') {
            return '';
        }

        return Str::headline(mb_strtolower($value));
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
