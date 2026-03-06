<?php

namespace App\Services\Recipes;

use App\Models\Blend;
use App\Models\Scent;

class NestedOilRecipeResolver
{
    /** @var array<string,array<int,array{name:string,weight:float}>> */
    protected array $blendComponentCache = [];

    /** @var array<string,array<int,array{name:string,weight:float}>> */
    protected array $scentComponentCache = [];

    protected bool $referenceLoaded = false;

    /**
     * @param  array<int,string>  $oilSlots
     * @return array<int,array{name:string,weight:float}>
     */
    public function parseTopLevelComponents(array $oilSlots): array
    {
        $components = [];

        foreach ($oilSlots as $rawSlot) {
            $parsed = $this->parseComponentValue($rawSlot);
            if (! $parsed) {
                continue;
            }

            $components[] = $parsed;
        }

        return $components;
    }

    /**
     * @param  array<string,array<int,array{name:string,weight:float}>>  $recipeDefinitions
     * @return array{components:array<int,array{name:string,weight:float,percent:float}>,errors:array<int,string>}
     */
    public function resolveToBaseOils(array $topLevelComponents, array $recipeDefinitions = []): array
    {
        $this->loadReferenceData();

        $errors = [];
        $resolved = [];

        foreach ($topLevelComponents as $component) {
            $name = trim((string) ($component['name'] ?? ''));
            $weight = (float) ($component['weight'] ?? 0.0);
            if ($name === '' || $weight <= 0) {
                continue;
            }

            $expanded = $this->expandComponent(
                $name,
                $weight,
                $recipeDefinitions,
                [],
                $errors
            );

            foreach ($expanded as $row) {
                $resolved[] = $row;
            }
        }

        $collapsed = $this->collapseComponents($resolved);
        $totalWeight = array_reduce($collapsed, fn (float $carry, array $row): float => $carry + (float) ($row['weight'] ?? 0.0), 0.0);

        $normalized = array_map(function (array $row) use ($totalWeight): array {
            $weight = (float) ($row['weight'] ?? 0.0);

            return [
                'name' => (string) ($row['name'] ?? ''),
                'weight' => $weight,
                'percent' => $totalWeight > 0 ? round(($weight / $totalWeight) * 100, 4) : 0.0,
            ];
        }, $collapsed);

        usort($normalized, fn (array $a, array $b): int => ($b['weight'] <=> $a['weight']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return [
            'components' => $normalized,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function lookupKey(string $value): string
    {
        $normalized = Scent::normalizeName($value);
        $normalized = preg_replace('/\s+blend$/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array{name:string,weight:float}|null
     */
    public function parseComponentValue(?string $rawValue): ?array
    {
        $raw = trim((string) $rawValue);
        if ($raw === '') {
            return null;
        }

        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
        $name = $raw;
        $weight = 1.0;

        if (preg_match('/^(\d+(?:\.\d+)?)\s*%\s+(.+)$/u', $raw, $matches) === 1) {
            $weight = max(0.0001, (float) $matches[1]);
            $name = trim((string) $matches[2]);
        } elseif (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)\s*%$/u', $raw, $matches) === 1) {
            $name = trim((string) $matches[1]);
            $weight = max(0.0001, (float) $matches[2]);
        } elseif (preg_match('/^(.+?)\s*\(\s*(\d+(?:\.\d+)?)\s*%\s*\)$/u', $raw, $matches) === 1) {
            $name = trim((string) $matches[1]);
            $weight = max(0.0001, (float) $matches[2]);
        } elseif (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)$/u', $raw, $matches) === 1) {
            $name = trim((string) $matches[1]);
            $weight = max(0.0001, (float) $matches[2]);
        } elseif (preg_match('/^(.+?)\((\d+(?:\.\d+)?)\)$/u', $raw, $matches) === 1) {
            $name = trim((string) $matches[1]);
            $weight = max(0.0001, (float) $matches[2]);
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'weight' => $weight,
        ];
    }

    /**
     * @param  array<string,array<int,array{name:string,weight:float}>>  $recipeDefinitions
     * @param  array<int,string>  $stack
     * @param  array<int,string>  $errors
     * @return array<int,array{name:string,weight:float}>
     */
    protected function expandComponent(string $name, float $weight, array $recipeDefinitions, array $stack, array &$errors): array
    {
        $lookupKey = $this->lookupKey($name);
        if ($lookupKey === '') {
            return [];
        }

        if (in_array($lookupKey, $stack, true)) {
            $cycle = implode(' -> ', array_merge($stack, [$lookupKey]));
            $errors[] = 'Circular recipe reference detected: '.$cycle;
            return [];
        }

        $nextStack = array_merge($stack, [$lookupKey]);

        if (array_key_exists($lookupKey, $recipeDefinitions)) {
            $expanded = [];
            foreach ((array) $recipeDefinitions[$lookupKey] as $component) {
                $componentName = trim((string) ($component['name'] ?? ''));
                $componentWeight = (float) ($component['weight'] ?? 0.0);
                if ($componentName === '' || $componentWeight <= 0) {
                    continue;
                }

                $rows = $this->expandComponent(
                    $componentName,
                    $weight * $componentWeight,
                    $recipeDefinitions,
                    $nextStack,
                    $errors
                );

                foreach ($rows as $row) {
                    $expanded[] = $row;
                }
            }

            // When a recipe definition exists, never fallback to treating the recipe label as a base oil.
            // This prevents circular/invalid formulas from silently producing incorrect base oil demand.
            return $expanded;
        }

        if (isset($this->blendComponentCache[$lookupKey])) {
            return array_map(fn (array $component): array => [
                'name' => (string) $component['name'],
                'weight' => (float) $component['weight'] * $weight,
            ], $this->blendComponentCache[$lookupKey]);
        }

        if (isset($this->scentComponentCache[$lookupKey])) {
            return array_map(fn (array $component): array => [
                'name' => (string) $component['name'],
                'weight' => (float) $component['weight'] * $weight,
            ], $this->scentComponentCache[$lookupKey]);
        }

        return [[
            'name' => $name,
            'weight' => $weight,
        ]];
    }

    /**
     * @param  array<int,array{name:string,weight:float}>  $components
     * @return array<int,array{name:string,weight:float}>
     */
    protected function collapseComponents(array $components): array
    {
        $collapsed = [];

        foreach ($components as $component) {
            $name = trim((string) ($component['name'] ?? ''));
            $weight = (float) ($component['weight'] ?? 0.0);

            if ($name === '' || $weight <= 0) {
                continue;
            }

            $key = mb_strtolower($name);
            if (! isset($collapsed[$key])) {
                $collapsed[$key] = [
                    'name' => $name,
                    'weight' => 0.0,
                ];
            }

            $collapsed[$key]['weight'] += $weight;
        }

        return array_values($collapsed);
    }

    protected function loadReferenceData(): void
    {
        if ($this->referenceLoaded) {
            return;
        }

        $blends = Blend::query()
            ->with(['components.baseOil'])
            ->get(['id', 'name']);

        foreach ($blends as $blend) {
            if (! $blend->relationLoaded('components')) {
                continue;
            }

            $rows = [];
            foreach ($blend->components as $component) {
                $baseOilName = trim((string) ($component->baseOil?->name ?? ''));
                if ($baseOilName === '') {
                    continue;
                }

                $rows[] = [
                    'name' => $baseOilName,
                    'weight' => (float) max(1, (int) $component->ratio_weight),
                ];
            }

            if ($rows === []) {
                continue;
            }

            $this->blendComponentCache[$this->lookupKey((string) $blend->name)] = $rows;
        }

        $scents = Scent::query()
            ->with(['oilBlend.components.baseOil'])
            ->whereNotNull('oil_blend_id')
            ->get(['id', 'name', 'display_name', 'abbreviation', 'oil_blend_id']);

        foreach ($scents as $scent) {
            $components = $scent->oilBlend?->components ?? collect();
            if ($components->isEmpty()) {
                continue;
            }

            $rows = [];
            foreach ($components as $component) {
                $baseOilName = trim((string) ($component->baseOil?->name ?? ''));
                if ($baseOilName === '') {
                    continue;
                }

                $rows[] = [
                    'name' => $baseOilName,
                    'weight' => (float) max(1, (int) $component->ratio_weight),
                ];
            }

            if ($rows === []) {
                continue;
            }

            foreach (array_filter([
                $scent->display_name,
                $scent->name,
                $scent->abbreviation,
            ]) as $lookupValue) {
                $this->scentComponentCache[$this->lookupKey((string) $lookupValue)] = $rows;
            }
        }

        $this->referenceLoaded = true;
    }
}
