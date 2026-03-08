<?php

namespace App\Actions\ScentGovernance;

use App\Models\Scent;
use App\Models\ScentAlias;

class CreateScentAliasAction
{
    /**
     * @param  Scent|int  $scent
     * @param  array<int,string>  $canonicalValues
     */
    public function execute(Scent|int $scent, string $alias, string $scope, array $canonicalValues = []): ?ScentAlias
    {
        $scentId = $scent instanceof Scent ? (int) $scent->id : (int) $scent;
        if ($scentId <= 0) {
            return null;
        }

        $scope = trim($scope);
        if ($scope === '') {
            $scope = 'global';
        }

        $normalizedAlias = ScentAlias::normalizeLabel(mb_substr($alias, 0, 255));
        if ($normalizedAlias === '') {
            return null;
        }

        $normalizedCanonical = collect($canonicalValues)
            ->map(fn (string $value): string => ScentAlias::normalizeLabel($value))
            ->filter()
            ->values()
            ->all();

        if (in_array($normalizedAlias, $normalizedCanonical, true)) {
            return null;
        }

        return ScentAlias::query()->updateOrCreate(
            ['alias' => $normalizedAlias, 'scope' => $scope],
            ['scent_id' => $scentId]
        );
    }

    /**
     * @param  Scent|int  $scent
     * @param  array<int,string>  $aliases
     * @param  array<int,string>  $scopes
     * @param  array<int,string>  $canonicalValues
     */
    public function syncAcrossScopes(Scent|int $scent, array $aliases, array $scopes, array $canonicalValues = []): int
    {
        $count = 0;
        $scopes = array_values(array_unique(array_filter(array_map('trim', $scopes))));
        if ($scopes === []) {
            $scopes = ['global'];
        }

        $uniqueAliases = collect($aliases)
            ->map(fn (string $alias): string => trim($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($uniqueAliases as $alias) {
            foreach ($scopes as $scope) {
                $record = $this->execute($scent, $alias, $scope, $canonicalValues);
                if ($record) {
                    $count++;
                }
            }
        }

        return $count;
    }
}

