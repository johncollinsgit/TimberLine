<?php

namespace App\Services\Recipes;

use App\Models\BaseOil;
use App\Models\BlendTemplate;
use App\Models\BlendTemplateComponent;
use App\Models\Scent;
use App\Models\ScentRecipe;
use App\Models\ScentRecipeComponent;
use App\Services\Recipes\Exceptions\FormulaCycleDetectedException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class FlattenFormulaService
{
    protected const EPSILON = 0.0000001;

    /** @var array<int,BlendTemplate|null> */
    protected array $blendTemplateCache = [];

    /**
     * @return array<string,mixed>
     */
    public function flattenScent(
        Scent|int $scent,
        ?float $totalGrams = null,
        bool $allowLegacyFallback = false,
        bool $includeTree = true
    ): array {
        $scentModel = $scent instanceof Scent
            ? $scent->loadMissing('currentRecipe.components.baseOil', 'activeRecipe.components.baseOil')
            : Scent::query()
                ->with('currentRecipe.components.baseOil', 'activeRecipe.components.baseOil')
                ->findOrFail($scent);

        $recipe = $scentModel->currentRecipe;
        if (! $recipe) {
            $recipe = $scentModel->activeRecipe;
        }

        if ($recipe) {
            $result = $this->flattenRecipe($recipe, $totalGrams, $includeTree);
            $result['source']['kind'] = 'scent';
            $result['source']['scent_id'] = (int) $scentModel->id;
            $result['source']['scent_name'] = (string) ($scentModel->display_name ?: $scentModel->name);

            return $result;
        }

        if (! $allowLegacyFallback) {
            return $this->emptyResult(
                source: [
                    'kind' => 'scent',
                    'scent_id' => (int) $scentModel->id,
                    'scent_name' => (string) ($scentModel->display_name ?: $scentModel->name),
                    'scent_recipe_id' => null,
                ],
                totalGrams: $totalGrams,
                includeTree: $includeTree,
                unresolved: [[
                    'reason' => 'No active scent recipe found for this scent.',
                    'component_type' => 'scent_recipe',
                    'reference_id' => null,
                    'path' => [],
                ]]
            );
        }

        return $this->flattenLegacyScent($scentModel, $totalGrams, $includeTree);
    }

    /**
     * @return array<string,mixed>
     */
    public function flattenRecipe(ScentRecipe|int $recipe, ?float $totalGrams = null, bool $includeTree = true): array
    {
        $recipeModel = $recipe instanceof ScentRecipe
            ? $recipe->loadMissing('components.baseOil', 'components.blendTemplate')
            : ScentRecipe::query()
                ->with('components.baseOil', 'components.blendTemplate')
                ->findOrFail($recipe);

        $stack = ['recipe:'.$recipeModel->id];
        $expanded = $this->expandScentRecipeComponents(
            $recipeModel->components,
            parentShare: 1.0,
            stack: $stack,
            includeTree: $includeTree
        );

        return $this->formatResult(
            source: [
                'kind' => 'scent_recipe',
                'scent_recipe_id' => (int) $recipeModel->id,
                'scent_id' => (int) $recipeModel->scent_id,
                'version' => (int) $recipeModel->version,
                'status' => (string) $recipeModel->status,
            ],
            totalGrams: $totalGrams,
            allocations: $expanded['allocations'],
            unresolved: $expanded['unresolved'],
            includeTree: $includeTree,
            tree: $includeTree ? [[
                'node_type' => 'scent_recipe',
                'node_id' => (int) $recipeModel->id,
                'label' => 'Scent recipe #'.$recipeModel->id,
                'effective_share' => 100.0,
                'children' => $expanded['tree'],
            ]] : []
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function flattenBlendTemplate(BlendTemplate|int $blendTemplate, ?float $totalGrams = null, bool $includeTree = true): array
    {
        $template = $blendTemplate instanceof BlendTemplate
            ? $blendTemplate->loadMissing('templateComponents.baseOil', 'templateComponents.blendTemplate')
            : $this->loadBlendTemplate((int) $blendTemplate);

        if (! $template) {
            return $this->emptyResult(
                source: [
                    'kind' => 'blend_template',
                    'blend_template_id' => is_int($blendTemplate) ? $blendTemplate : (int) $blendTemplate->id,
                ],
                totalGrams: $totalGrams,
                includeTree: $includeTree,
                unresolved: [[
                    'reason' => 'Blend template not found.',
                    'component_type' => 'blend_template',
                    'reference_id' => is_int($blendTemplate) ? $blendTemplate : (int) $blendTemplate->id,
                    'path' => [],
                ]]
            );
        }

        $stack = ['blend:'.$template->id];
        $expanded = $this->expandBlendTemplate(
            $template,
            parentShare: 1.0,
            stack: $stack,
            includeTree: $includeTree
        );

        return $this->formatResult(
            source: [
                'kind' => 'blend_template',
                'blend_template_id' => (int) $template->id,
                'blend_template_name' => (string) $template->name,
            ],
            totalGrams: $totalGrams,
            allocations: $expanded['allocations'],
            unresolved: $expanded['unresolved'],
            includeTree: $includeTree,
            tree: $includeTree ? [[
                'node_type' => 'blend_template',
                'node_id' => (int) $template->id,
                'label' => (string) $template->name,
                'effective_share' => 100.0,
                'children' => $expanded['tree'],
            ]] : []
        );
    }

    /**
     * @return array{allocations:array<int,float>,unresolved:array<int,array<string,mixed>>,tree:array<int,array<string,mixed>>}
     */
    protected function expandScentRecipeComponents(
        EloquentCollection $components,
        float $parentShare,
        array $stack,
        bool $includeTree
    ): array {
        $rows = $components->values()->all();
        $shares = $this->computeShares($rows, fn ($row): ?float => $row->percentage, fn ($row): ?float => $row->parts);

        $allocations = [];
        $unresolved = [];
        $tree = [];

        foreach ($rows as $index => $component) {
            $share = (float) ($shares[$index] ?? 0.0);
            if ($share <= self::EPSILON) {
                continue;
            }

            $effectiveShare = $parentShare * $share;

            if ($component->component_type === ScentRecipeComponent::TYPE_OIL) {
                $baseOil = $component->baseOil;
                if (! $baseOil || ! $baseOil->id) {
                    $unresolved[] = $this->unresolved(
                        reason: 'Recipe component oil reference is missing.',
                        componentType: ScentRecipeComponent::TYPE_OIL,
                        referenceId: $component->base_oil_id ? (int) $component->base_oil_id : null,
                        path: $stack
                    );
                    continue;
                }

                $allocations[(int) $baseOil->id] = ($allocations[(int) $baseOil->id] ?? 0.0) + $effectiveShare;

                if ($includeTree) {
                    $tree[] = [
                        'node_type' => 'component',
                        'component_id' => (int) $component->id,
                        'component_type' => ScentRecipeComponent::TYPE_OIL,
                        'label' => (string) $baseOil->name,
                        'base_oil_id' => (int) $baseOil->id,
                        'input_percentage' => $component->percentage !== null ? (float) $component->percentage : null,
                        'input_parts' => $component->parts !== null ? (float) $component->parts : null,
                        'normalized_share' => round($share * 100, 6),
                        'effective_share' => round($effectiveShare * 100, 6),
                        'children' => [],
                    ];
                }

                continue;
            }

            if ($component->component_type === ScentRecipeComponent::TYPE_BLEND_TEMPLATE) {
                if (! $component->blend_template_id) {
                    $unresolved[] = $this->unresolved(
                        reason: 'Recipe blend-template component has no blend template id.',
                        componentType: ScentRecipeComponent::TYPE_BLEND_TEMPLATE,
                        referenceId: null,
                        path: $stack
                    );
                    continue;
                }

                $child = $this->expandBlendTemplateById(
                    blendTemplateId: (int) $component->blend_template_id,
                    parentShare: $effectiveShare,
                    stack: $stack,
                    includeTree: $includeTree
                );

                $allocations = $this->mergeAllocations($allocations, $child['allocations']);
                array_push($unresolved, ...$child['unresolved']);

                if ($includeTree) {
                    $tree[] = [
                        'node_type' => 'component',
                        'component_id' => (int) $component->id,
                        'component_type' => ScentRecipeComponent::TYPE_BLEND_TEMPLATE,
                        'label' => $child['label'],
                        'blend_template_id' => (int) $component->blend_template_id,
                        'input_percentage' => $component->percentage !== null ? (float) $component->percentage : null,
                        'input_parts' => $component->parts !== null ? (float) $component->parts : null,
                        'normalized_share' => round($share * 100, 6),
                        'effective_share' => round($effectiveShare * 100, 6),
                        'children' => $child['tree'],
                    ];
                }

                continue;
            }

            $unresolved[] = $this->unresolved(
                reason: 'Unknown scent recipe component type.',
                componentType: (string) $component->component_type,
                referenceId: (int) $component->id,
                path: $stack
            );
        }

        return [
            'allocations' => $allocations,
            'unresolved' => $unresolved,
            'tree' => $tree,
        ];
    }

    /**
     * @return array{allocations:array<int,float>,unresolved:array<int,array<string,mixed>>,tree:array<int,array<string,mixed>>,label:string}
     */
    protected function expandBlendTemplateById(int $blendTemplateId, float $parentShare, array $stack, bool $includeTree): array
    {
        $key = 'blend:'.$blendTemplateId;
        if (in_array($key, $stack, true)) {
            throw new FormulaCycleDetectedException(array_merge($stack, [$key]));
        }

        $template = $this->loadBlendTemplate($blendTemplateId);
        if (! $template) {
            return [
                'allocations' => [],
                'unresolved' => [$this->unresolved(
                    reason: 'Blend template reference not found.',
                    componentType: ScentRecipeComponent::TYPE_BLEND_TEMPLATE,
                    referenceId: $blendTemplateId,
                    path: $stack
                )],
                'tree' => [],
                'label' => 'Blend template #'.$blendTemplateId,
            ];
        }

        $expanded = $this->expandBlendTemplate(
            $template,
            parentShare: $parentShare,
            stack: array_merge($stack, [$key]),
            includeTree: $includeTree
        );
        $expanded['label'] = (string) $template->name;

        return $expanded;
    }

    /**
     * @return array{allocations:array<int,float>,unresolved:array<int,array<string,mixed>>,tree:array<int,array<string,mixed>>}
     */
    protected function expandBlendTemplate(BlendTemplate $template, float $parentShare, array $stack, bool $includeTree): array
    {
        $rows = $template->templateComponents->values()->all();
        $shares = $this->computeShares($rows, fn ($row): ?float => $row->percentage, fn ($row): ?float => $row->ratio_weight);

        $allocations = [];
        $unresolved = [];
        $tree = [];

        foreach ($rows as $index => $component) {
            $share = (float) ($shares[$index] ?? 0.0);
            if ($share <= self::EPSILON) {
                continue;
            }

            $effectiveShare = $parentShare * $share;

            if ($component->component_type === BlendTemplateComponent::TYPE_OIL) {
                $baseOil = $component->baseOil;
                if (! $baseOil || ! $baseOil->id) {
                    $unresolved[] = $this->unresolved(
                        reason: 'Blend template component oil reference is missing.',
                        componentType: BlendTemplateComponent::TYPE_OIL,
                        referenceId: $component->base_oil_id ? (int) $component->base_oil_id : null,
                        path: $stack
                    );
                    continue;
                }

                $allocations[(int) $baseOil->id] = ($allocations[(int) $baseOil->id] ?? 0.0) + $effectiveShare;

                if ($includeTree) {
                    $tree[] = [
                        'node_type' => 'component',
                        'component_id' => (int) $component->id,
                        'component_type' => BlendTemplateComponent::TYPE_OIL,
                        'label' => (string) $baseOil->name,
                        'base_oil_id' => (int) $baseOil->id,
                        'input_percentage' => $component->percentage !== null ? (float) $component->percentage : null,
                        'input_parts' => $component->ratio_weight !== null ? (float) $component->ratio_weight : null,
                        'normalized_share' => round($share * 100, 6),
                        'effective_share' => round($effectiveShare * 100, 6),
                        'children' => [],
                    ];
                }

                continue;
            }

            if ($component->component_type === BlendTemplateComponent::TYPE_BLEND_TEMPLATE) {
                if (! $component->blend_template_id) {
                    $unresolved[] = $this->unresolved(
                        reason: 'Nested blend template component has no blend template id.',
                        componentType: BlendTemplateComponent::TYPE_BLEND_TEMPLATE,
                        referenceId: null,
                        path: $stack
                    );
                    continue;
                }

                $child = $this->expandBlendTemplateById(
                    blendTemplateId: (int) $component->blend_template_id,
                    parentShare: $effectiveShare,
                    stack: $stack,
                    includeTree: $includeTree
                );

                $allocations = $this->mergeAllocations($allocations, $child['allocations']);
                array_push($unresolved, ...$child['unresolved']);

                if ($includeTree) {
                    $tree[] = [
                        'node_type' => 'component',
                        'component_id' => (int) $component->id,
                        'component_type' => BlendTemplateComponent::TYPE_BLEND_TEMPLATE,
                        'label' => $child['label'],
                        'blend_template_id' => (int) $component->blend_template_id,
                        'input_percentage' => $component->percentage !== null ? (float) $component->percentage : null,
                        'input_parts' => $component->ratio_weight !== null ? (float) $component->ratio_weight : null,
                        'normalized_share' => round($share * 100, 6),
                        'effective_share' => round($effectiveShare * 100, 6),
                        'children' => $child['tree'],
                    ];
                }

                continue;
            }

            $unresolved[] = $this->unresolved(
                reason: 'Unknown blend template component type.',
                componentType: (string) $component->component_type,
                referenceId: (int) $component->id,
                path: $stack
            );
        }

        return [
            'allocations' => $allocations,
            'unresolved' => $unresolved,
            'tree' => $tree,
        ];
    }

    protected function loadBlendTemplate(int $id): ?BlendTemplate
    {
        if (array_key_exists($id, $this->blendTemplateCache)) {
            return $this->blendTemplateCache[$id];
        }

        $this->blendTemplateCache[$id] = BlendTemplate::query()
            ->with('templateComponents.baseOil', 'templateComponents.blendTemplate')
            ->find($id);

        return $this->blendTemplateCache[$id];
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return array<int,float>
     */
    protected function computeShares(array $rows, callable $percentageValue, callable $partsValue): array
    {
        $count = count($rows);
        if ($count === 0) {
            return [];
        }

        $percentages = [];
        $parts = [];
        $missingIndexes = [];

        foreach ($rows as $index => $row) {
            $percent = $this->positiveNumber($percentageValue($row));
            $part = $this->positiveNumber($partsValue($row));

            if ($percent !== null) {
                $percentages[$index] = $percent;
                continue;
            }

            if ($part !== null) {
                $parts[$index] = $part;
                continue;
            }

            $missingIndexes[] = $index;
        }

        $weights = array_fill(0, $count, 0.0);

        if ($percentages !== []) {
            foreach ($percentages as $index => $value) {
                $weights[$index] = $value;
            }

            $percentTotal = array_sum($percentages);
            $remaining = max(0.0, 100.0 - $percentTotal);

            if ($remaining > self::EPSILON && $parts !== []) {
                $partsTotal = array_sum($parts);
                if ($partsTotal > self::EPSILON) {
                    foreach ($parts as $index => $value) {
                        $weights[$index] = $remaining * ($value / $partsTotal);
                    }
                }
            } elseif ($remaining > self::EPSILON && $missingIndexes !== []) {
                $each = $remaining / count($missingIndexes);
                foreach ($missingIndexes as $index) {
                    $weights[$index] = $each;
                }
            }
        } elseif ($parts !== []) {
            foreach ($parts as $index => $value) {
                $weights[$index] = $value;
            }

            if ($missingIndexes !== []) {
                foreach ($missingIndexes as $index) {
                    $weights[$index] = 1.0;
                }
            }
        } else {
            foreach (array_keys($weights) as $index) {
                $weights[$index] = 1.0;
            }
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= self::EPSILON) {
            return array_fill(0, $count, 1.0 / $count);
        }

        $shares = [];
        foreach ($weights as $index => $weight) {
            $shares[$index] = $weight / $totalWeight;
        }

        return $shares;
    }

    /**
     * @param  array<int,float>  $left
     * @param  array<int,float>  $right
     * @return array<int,float>
     */
    protected function mergeAllocations(array $left, array $right): array
    {
        foreach ($right as $oilId => $share) {
            $left[(int) $oilId] = ($left[(int) $oilId] ?? 0.0) + (float) $share;
        }

        return $left;
    }

    /**
     * @param  array<int,float>  $allocations
     * @param  array<int,array<string,mixed>>  $unresolved
     * @param  array<int,array<string,mixed>>  $tree
     * @return array<string,mixed>
     */
    protected function formatResult(
        array $source,
        ?float $totalGrams,
        array $allocations,
        array $unresolved,
        bool $includeTree,
        array $tree
    ): array {
        $rows = [];
        foreach ($allocations as $oilId => $share) {
            $oil = BaseOil::query()->find($oilId);
            if (! $oil) {
                $unresolved[] = $this->unresolved(
                    reason: 'Flattened allocation references a missing base oil.',
                    componentType: 'oil',
                    referenceId: (int) $oilId,
                    path: []
                );
                continue;
            }

            $percentage = round((float) $share * 100, 6);
            $grams = $totalGrams !== null ? round((float) $share * $totalGrams, 4) : null;

            $rows[] = [
                'base_oil_id' => (int) $oilId,
                'base_oil_name' => (string) $oil->name,
                'percentage' => $percentage,
                'grams' => $grams,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $byPct = ((float) $b['percentage']) <=> ((float) $a['percentage']);
            if ($byPct !== 0) {
                return $byPct;
            }

            return strcmp((string) $a['base_oil_name'], (string) $b['base_oil_name']);
        });

        $byOilId = [];
        foreach ($rows as $row) {
            $byOilId[(string) $row['base_oil_id']] = $row;
        }

        return [
            'source' => $source,
            'total_grams' => $totalGrams,
            'percent_total' => round((float) array_sum(array_column($rows, 'percentage')), 6),
            'components' => $rows,
            'by_oil_id' => $byOilId,
            'unresolved' => $unresolved,
            'tree' => $includeTree ? $tree : [],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $unresolved
     * @return array<string,mixed>
     */
    protected function emptyResult(array $source, ?float $totalGrams, bool $includeTree, array $unresolved = []): array
    {
        return [
            'source' => $source,
            'total_grams' => $totalGrams,
            'percent_total' => 0.0,
            'components' => [],
            'by_oil_id' => [],
            'unresolved' => $unresolved,
            'tree' => $includeTree ? [] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function flattenLegacyScent(Scent $scent, ?float $totalGrams, bool $includeTree): array
    {
        $allocations = [];
        $unresolved = [];
        $tree = [];

        if ($scent->oil_blend_id) {
            $expanded = $this->expandBlendTemplateById(
                blendTemplateId: (int) $scent->oil_blend_id,
                parentShare: 1.0,
                stack: ['legacy-scent:'.$scent->id],
                includeTree: $includeTree
            );
            $allocations = $this->mergeAllocations($allocations, $expanded['allocations']);
            array_push($unresolved, ...$expanded['unresolved']);

            if ($includeTree) {
                $tree[] = [
                    'node_type' => 'legacy_blend',
                    'label' => 'Legacy blend reference',
                    'blend_template_id' => (int) $scent->oil_blend_id,
                    'effective_share' => 100.0,
                    'children' => $expanded['tree'],
                ];
            }
        } else {
            $oilName = trim((string) ($scent->oil_reference_name ?? ''));
            if ($oilName !== '') {
                $oil = BaseOil::query()
                    ->whereRaw('lower(name) = ?', [mb_strtolower($oilName)])
                    ->first();

                if ($oil) {
                    $allocations[(int) $oil->id] = ($allocations[(int) $oil->id] ?? 0.0) + 1.0;
                    if ($includeTree) {
                        $tree[] = [
                            'node_type' => 'legacy_oil',
                            'label' => (string) $oil->name,
                            'base_oil_id' => (int) $oil->id,
                            'effective_share' => 100.0,
                            'children' => [],
                        ];
                    }
                } else {
                    $unresolved[] = $this->unresolved(
                        reason: 'Legacy oil reference name could not be mapped to a base oil.',
                        componentType: 'oil',
                        referenceId: null,
                        path: ['legacy-scent:'.$scent->id]
                    );
                }
            } else {
                $unresolved[] = $this->unresolved(
                    reason: 'No active recipe and no legacy blend/oil fallback references were found.',
                    componentType: 'scent_recipe',
                    referenceId: null,
                    path: ['legacy-scent:'.$scent->id]
                );
            }
        }

        return $this->formatResult(
            source: [
                'kind' => 'scent',
                'scent_id' => (int) $scent->id,
                'scent_name' => (string) ($scent->display_name ?: $scent->name),
                'scent_recipe_id' => null,
                'fallback' => 'legacy',
            ],
            totalGrams: $totalGrams,
            allocations: $allocations,
            unresolved: $unresolved,
            includeTree: $includeTree,
            tree: $tree
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function unresolved(string $reason, string $componentType, ?int $referenceId, array $path): array
    {
        return [
            'reason' => $reason,
            'component_type' => $componentType,
            'reference_id' => $referenceId,
            'path' => array_values($path),
        ];
    }

    protected function positiveNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;
        if ($number <= self::EPSILON) {
            return null;
        }

        return $number;
    }
}
