<?php

namespace App\Services;

use InvalidArgumentException;

class MarketBoxService
{
    /**
     * Expand box counts into concrete pour quantities.
     *
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    public function expand(string $boxType, int $count, array $topShelfDefinition = []): array
    {
        $count = max(0, $count);
        $boxType = strtolower(trim($boxType));

        if ($count === 0) {
            return $this->emptyTotals();
        }

        return match ($boxType) {
            'full' => [
                '16oz' => 4 * $count,
                '8oz' => 8 * $count,
                'wax_melt' => 8 * $count,
            ],
            'half' => [
                '16oz' => 2 * $count,
                '8oz' => 4 * $count,
                'wax_melt' => 4 * $count,
            ],
            'top_shelf', 'top-shelf' => $this->expandTopShelf($count, $topShelfDefinition),
            default => throw new InvalidArgumentException("Unsupported market box type [{$boxType}]"),
        };
    }

    /**
     * @param  iterable<array{box_type:string,box_count:int,top_shelf_definition?:array}>  $rows
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    public function expandMany(iterable $rows): array
    {
        $totals = $this->emptyTotals();

        foreach ($rows as $row) {
            $expanded = $this->expand(
                (string) ($row['box_type'] ?? ''),
                (int) ($row['box_count'] ?? 0),
                (array) ($row['top_shelf_definition'] ?? [])
            );

            $totals = $this->mergeTotals($totals, $expanded);
        }

        return $totals;
    }

    /**
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    public function normalizeTopShelfDefinition(array $definition): array
    {
        return [
            '16oz' => max(0, (int) ($definition['16oz'] ?? $definition['16_oz'] ?? 0)),
            '8oz' => max(0, (int) ($definition['8oz'] ?? $definition['8_oz'] ?? 0)),
            'wax_melt' => max(0, (int) ($definition['wax_melt'] ?? $definition['wax_melts'] ?? 0)),
        ];
    }

    /**
     * @param  array{16oz:int,8oz:int,wax_melt:int}  $left
     * @param  array{16oz:int,8oz:int,wax_melt:int}  $right
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    public function mergeTotals(array $left, array $right): array
    {
        return [
            '16oz' => (int) ($left['16oz'] ?? 0) + (int) ($right['16oz'] ?? 0),
            '8oz' => (int) ($left['8oz'] ?? 0) + (int) ($right['8oz'] ?? 0),
            'wax_melt' => (int) ($left['wax_melt'] ?? 0) + (int) ($right['wax_melt'] ?? 0),
        ];
    }

    /**
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    public function emptyTotals(): array
    {
        return [
            '16oz' => 0,
            '8oz' => 0,
            'wax_melt' => 0,
        ];
    }

    /**
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    protected function expandTopShelf(int $count, array $topShelfDefinition): array
    {
        $definition = $this->normalizeTopShelfDefinition($topShelfDefinition);

        return [
            '16oz' => $definition['16oz'] * $count,
            '8oz' => $definition['8oz'] * $count,
            'wax_melt' => $definition['wax_melt'] * $count,
        ];
    }
}
