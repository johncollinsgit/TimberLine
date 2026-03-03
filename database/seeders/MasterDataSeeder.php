<?php

namespace Database\Seeders;

use App\Models\OilAbbreviation;
use App\Models\Scent;
use App\Models\ScentAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,
            BlendRecipeSeeder::class,
            WholesaleCustomScentsSeeder::class,
        ]);

        $this->seedOilAbbreviations();
        $this->seedScentAliases();
    }

    protected function seedOilAbbreviations(): void
    {
        if (! Schema::hasTable('oil_abbreviations')) {
            return;
        }

        Scent::query()
            ->whereNotNull('abbreviation')
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'abbreviation', 'oil_reference_name', 'is_blend'])
            ->each(function (Scent $scent): void {
                $abbreviation = $this->normalizeNullable((string) ($scent->abbreviation ?? ''));
                if ($abbreviation === null) {
                    return;
                }

                $name = $this->oilAbbreviationName($scent);
                if ($name === null) {
                    return;
                }

                OilAbbreviation::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'abbreviation' => $abbreviation,
                        'is_active' => true,
                    ]
                );
            });
    }

    protected function seedScentAliases(): void
    {
        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        Scent::query()
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'abbreviation'])
            ->each(function (Scent $scent): void {
                $aliases = [];
                $displayName = $this->normalizeText((string) ($scent->display_name ?? ''));
                $canonicalName = $this->normalizeText((string) $scent->name);
                $abbreviation = $this->normalizeNullable((string) ($scent->abbreviation ?? ''));

                if (
                    $displayName !== '' &&
                    Scent::normalizeName($displayName) !== Scent::normalizeName($canonicalName)
                ) {
                    $aliases[] = $displayName;
                }

                if ($abbreviation !== null) {
                    $aliases[] = $abbreviation;
                }

                foreach (array_values(array_unique($aliases)) as $alias) {
                    ScentAlias::query()->firstOrCreate(
                        [
                            'alias' => $alias,
                            'scope' => 'markets',
                        ],
                        ['scent_id' => $scent->id]
                    );
                }
            });
    }

    protected function oilAbbreviationName(Scent $scent): ?string
    {
        $name = $this->normalizeText((string) ($scent->oil_reference_name ?: $scent->display_name ?: $scent->name));
        if ($name === '') {
            return null;
        }

        if ((bool) $scent->is_blend && ! str_ends_with(strtolower($name), ' blend')) {
            $name .= ' Blend';
        }

        return $name;
    }

    protected function normalizeNullable(string $value): ?string
    {
        $value = $this->normalizeText($value);

        return $value === '' ? null : $value;
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? $value : '';
    }
}
