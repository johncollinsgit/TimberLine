<?php

namespace App\Enterprise;

/** Stateless projection of already-authorized records; never retrieves or grants authority. */
final class GroundedEvidence
{
    public static function selected(array $records): array
    {
        $unique = [];
        foreach ($records['sources'] as $source) {
            $unique[$source['ref']] ??= $source;
        }
        $sources = array_values($unique);
        if (($records['building'] ?? null) !== null) {
            // Give each available evidence kind a slot before filling the remaining bounded slots.
            // This is coverage, not a relevance inference or a complete building report.
            $groups = [];
            foreach ($sources as $source) {
                $groups[explode(':', $source['ref'], 2)[0]][] = $source;
            }
            $sources = [];
            while ($groups !== [] && count($sources) < 8) {
                foreach ($groups as $kind => &$group) {
                    $sources[] = array_shift($group);
                    if ($group === []) {
                        unset($groups[$kind]);
                    }
                    if (count($sources) === 8) {
                        break;
                    }
                }
                unset($group);
            }
        }
        $sources = array_slice($sources, 0, 8);
        $byRef = array_column($sources, null, 'ref');
        $calculations = [];
        foreach ($records['comparisons'] as $group) {
            $inputs = function (string $basis) use ($group, $byRef): array {
                return array_values(array_filter(array_unique($group[$basis.'_refs']), function ($ref) use ($basis, $group, $byRef): bool {
                    $facts = $byRef[$ref]['facts'] ?? [];

                    return is_string($ref) && str_starts_with($ref, 'cost:') && isset($byRef[$ref])
                        && ($facts['project_id'] ?? null) === $group['project_id']
                        && ($facts['currency'] ?? null) === $group['currency'] && ($facts['basis'] ?? null) === $basis
                        && is_int($facts['amount_minor'] ?? null) && $facts['amount_minor'] >= 0 && $facts['amount_minor'] <= 1000000000;
                }));
            };
            $estimate = $inputs('estimate');
            $recorded = $inputs('recorded_cost');
            if ($estimate === [] && $recorded === []) {
                continue;
            }
            $sum = fn (array $refs) => array_sum(array_map(fn ($ref) => $byRef[$ref]['facts']['amount_minor'], $refs));
            $calculations[] = ['project' => $group['project_title'], 'currency' => $group['currency'],
                'estimate_minor' => $estimate !== [] ? $sum($estimate) : null,
                'recorded_cost_minor' => $recorded !== [] ? $sum($recorded) : null,
                'difference_minor' => $estimate !== [] && $recorded !== [] ? $sum($recorded) - $sum($estimate) : null,
                'input_refs' => [...$estimate, ...$recorded],
                'basis' => 'Checked selected project-level inputs only; no building allocation or approved budget.'];
        }

        return compact('sources', 'calculations');
    }

    /** Full bounded current graph, including unselected records, invalidates retained history/replay. */
    public static function stamp(array $records): array
    {
        return array_intersect_key($records, array_flip(['sources', 'comparisons', 'jobs', 'contractors', 'building', 'coverage', 'partial']));
    }
}
