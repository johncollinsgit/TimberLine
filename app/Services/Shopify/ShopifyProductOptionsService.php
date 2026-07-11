<?php

namespace App\Services\Shopify;

use App\Models\ShopifyProductOptionAssignment;
use App\Models\ShopifyProductOptionRuleset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopifyProductOptionsService
{
    public const MODULE_KEY = 'shopify_product_options';

    /**
     * @return array<string,mixed>
     */
    public function adminPayload(int $tenantId): array
    {
        $rulesets = ShopifyProductOptionRuleset::query()
            ->forTenantId($tenantId)
            ->with(['assignments' => fn ($query) => $query->orderBy('product_handle')])
            ->orderBy('name')
            ->get()
            ->map(fn (ShopifyProductOptionRuleset $ruleset): array => $this->rulesetPayload($ruleset))
            ->values()
            ->all();

        return [
            'module' => [
                'key' => self::MODULE_KEY,
                'label' => 'Product Options',
                'classification' => 'shopify-only',
                'tenant' => 'Modern Forestry',
            ],
            'rulesets' => $rulesets,
            'summary' => [
                'ruleset_count' => count($rulesets),
                'active_count' => collect($rulesets)->where('enabled', true)->count(),
                'assigned_product_count' => collect($rulesets)->sum(fn (array $ruleset): int => count($ruleset['assignments'])),
                'needs_assignment_count' => collect($rulesets)->filter(fn (array $ruleset): bool => $ruleset['assignments'] === [])->count(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function createRuleset(int $tenantId, array $attributes): array
    {
        return DB::transaction(function () use ($tenantId, $attributes): array {
            $ruleset = ShopifyProductOptionRuleset::query()->create([
                'tenant_id' => $tenantId,
                'name' => trim((string) $attributes['name']),
                'option_count' => (int) $attributes['option_count'],
                'allowed_values' => $this->normalizeValues((array) $attributes['allowed_values']),
                'require_distinct_values' => (bool) ($attributes['require_distinct_values'] ?? false),
                'enabled' => (bool) ($attributes['enabled'] ?? true),
                'source' => 'everbranch_admin',
                'metadata' => ['import_status' => 'managed_in_everbranch'],
            ]);

            $this->syncAssignments($ruleset, $tenantId, (array) ($attributes['product_handles'] ?? []));

            return $this->rulesetPayload($ruleset->fresh('assignments'));
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function updateRuleset(ShopifyProductOptionRuleset $ruleset, int $tenantId, array $attributes): array
    {
        abort_unless((int) $ruleset->tenant_id === $tenantId, 404);

        return DB::transaction(function () use ($ruleset, $tenantId, $attributes): array {
            $ruleset->fill([
                'name' => trim((string) $attributes['name']),
                'option_count' => (int) $attributes['option_count'],
                'allowed_values' => $this->normalizeValues((array) $attributes['allowed_values']),
                'require_distinct_values' => (bool) ($attributes['require_distinct_values'] ?? false),
                'enabled' => (bool) ($attributes['enabled'] ?? false),
                'source' => 'everbranch_admin',
                'metadata' => array_merge((array) $ruleset->metadata, ['import_status' => 'managed_in_everbranch']),
            ])->save();

            $this->syncAssignments($ruleset, $tenantId, (array) ($attributes['product_handles'] ?? []));

            return $this->rulesetPayload($ruleset->fresh('assignments'));
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function storefrontRuleset(int $tenantId, ?string $productId, ?string $productHandle): ?array
    {
        $productId = $this->normalizeProductId($productId);
        $productHandle = $this->normalizeProductHandle($productHandle);

        if ($productId === null && $productHandle === null) {
            return null;
        }

        $assignment = ShopifyProductOptionAssignment::query()
            ->forTenantId($tenantId)
            ->where(function ($query) use ($productId, $productHandle): void {
                if ($productId !== null) {
                    $query->where('shopify_product_id', $productId);
                }

                if ($productHandle !== null) {
                    $method = $productId !== null ? 'orWhere' : 'where';
                    $query->{$method}('product_handle', $productHandle);
                }
            })
            ->with('ruleset')
            ->first();

        $ruleset = $assignment?->ruleset;
        if (! $ruleset || ! $ruleset->enabled) {
            return null;
        }

        $allowedValues = $this->normalizeValues((array) $ruleset->allowed_values);
        if ($allowedValues === []) {
            return null;
        }

        return [
            'id' => (int) $ruleset->id,
            'name' => (string) $ruleset->name,
            'option_count' => max(1, (int) $ruleset->option_count),
            'allowed_values' => $allowedValues,
            'require_distinct_values' => (bool) $ruleset->require_distinct_values,
            'line_item_property_prefix' => 'Scent',
            'product_handle' => $assignment?->product_handle,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rulesetPayload(ShopifyProductOptionRuleset $ruleset): array
    {
        return [
            'id' => (int) $ruleset->id,
            'name' => (string) $ruleset->name,
            'option_count' => (int) $ruleset->option_count,
            'allowed_values' => $this->normalizeValues((array) $ruleset->allowed_values),
            'require_distinct_values' => (bool) $ruleset->require_distinct_values,
            'enabled' => (bool) $ruleset->enabled,
            'source' => (string) $ruleset->source,
            'assignments' => $ruleset->assignments
                ->map(fn (ShopifyProductOptionAssignment $assignment): array => [
                    'id' => (int) $assignment->id,
                    'shopify_product_id' => $assignment->shopify_product_id,
                    'product_handle' => $assignment->product_handle,
                    'product_url' => $assignment->product_url,
                ])
                ->values()
                ->all(),
            'updated_at' => optional($ruleset->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int,mixed>  $handles
     */
    private function syncAssignments(ShopifyProductOptionRuleset $ruleset, int $tenantId, array $handles): void
    {
        $normalized = collect($handles)
            ->map(fn ($handle): ?string => $this->normalizeProductHandle(is_string($handle) ? $handle : null))
            ->filter()
            ->unique()
            ->values();

        $ruleset->assignments()->delete();

        $normalized->each(function (string $handle) use ($ruleset, $tenantId): void {
            $ruleset->assignments()->create([
                'tenant_id' => $tenantId,
                'product_handle' => $handle,
                'product_url' => 'https://theforestrystudio.com/products/'.$handle,
            ]);
        });
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function normalizeValues(array $values): array
    {
        return Collection::make($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->sort(fn (string $left, string $right): int => strnatcasecmp($left, $right))
            ->values()
            ->all();
    }

    private function normalizeProductHandle(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($value, PHP_URL_PATH);
            if (preg_match('#/products/([^/?\#]+)#i', $path, $matches)) {
                $value = $matches[1];
            }
        }

        $value = Str::slug($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeProductId(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            $value = (string) Str::afterLast($value, '/');
        }

        return ctype_digit($value) ? $value : null;
    }
}
