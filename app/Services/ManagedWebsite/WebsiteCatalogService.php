<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WebsiteCatalogService
{
    public function assertEditor(TenantSite $site, User $actor): void
    {
        abort_unless(app(ManagedWebsiteService::class)->editorEnabledFor($site->tenant), 423);
        $membership = $actor->tenants()->whereKey($site->tenant_id)->first();
        abort_unless($actor->is_active && $membership && (bool) ($membership->pivot->membership_active ?? true)
            && in_array($membership->pivot->role, ['admin', 'owner', 'tenant_owner', 'manager', 'marketing_manager'], true), 403);
    }

    public function saveProduct(TenantSite $site, ?WebsiteProduct $product, array $data, User $actor): WebsiteProduct
    {
        $this->assertEditor($site, $actor);

        return DB::transaction(function () use ($site, $product, $data, $actor) {
            TenantSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            if ($product) {
                $product = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($product->id);
                if (isset($data['revision'])) {
                    abort_unless((int) data_get($product->service_details, 'catalog_revision', 0) === (int) $data['revision'], 409, 'This product changed. Reload it before saving.');
                }
            }
            $before = $product?->load('variants', 'collections')->toArray();
            $product ??= new WebsiteProduct(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id]);
            $rules = [
                'title' => ['required', 'string', 'max:190'],
                'handle' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('website_products', 'handle')->where('tenant_site_id', $site->id)->ignore($product->id)],
                'description' => ['nullable', 'string', 'max:8000'], 'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
                'product_type' => ['required', Rule::in(['physical', 'service', 'quote'])], 'track_inventory' => ['required', 'boolean'],
                'media' => ['present', 'array', 'max:12'], 'media.*' => ['required', 'url:http,https', 'max:2048'],
                'collection_ids' => ['present', 'array', 'max:100'], 'collection_ids.*' => ['integer', 'distinct', Rule::exists('website_collections', 'id')->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)],
                'variants' => ['required', 'array', 'min:1', 'max:100'],
                'variants.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('website_product_variants', 'id')->where('tenant_id', $site->tenant_id)->where('website_product_id', $product->id ?? 0)],
                'variants.*.title' => ['required', 'string', 'max:190'], 'variants.*.sku' => ['nullable', 'string', 'max:120', 'distinct'],
                'variants.*.price' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
                'variants.*.compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
                'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
                'variants.*.inventory_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'variants.*.is_available' => ['required', 'boolean'],
                'variants.*.shipping_weight_ounces' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'image_alt' => ['nullable', 'string', 'max:500'], 'details' => ['nullable', 'string', 'max:8000'],
                'vendor' => ['nullable', 'string', 'max:190'], 'category' => ['nullable', 'string', 'max:190'], 'tags' => ['nullable', 'string', 'max:1000'],
                'seo_title' => ['nullable', 'string', 'max:190'], 'seo_description' => ['nullable', 'string', 'max:320'],
            ];
            $data = Validator::make($data, $rules)->validate();
            if ($product->status === 'active' || $data['status'] === 'active') {
                abort_unless(app(ManagedWebsiteService::class)->publishingEnabled(), 423, 'Publishing is paused. Active products cannot change right now.');
            }
            $existingIds = $product->exists ? $product->variants()->pluck('id')->all() : [];
            abort_if(array_diff($existingIds, array_filter(array_column($data['variants'], 'id'))), 422, 'Keep existing variants in the editor; turn availability off to retire a variant.');
            $details = array_merge($product->service_details ?? [], array_intersect_key($data, array_flip(['image_alt', 'details', 'vendor', 'category', 'tags'])));
            $details['catalog_revision'] = (int) ($details['catalog_revision'] ?? 0) + 1;
            $product->fill(array_intersect_key($data, array_flip(['title', 'handle', 'description', 'status', 'product_type', 'track_inventory', 'media'])));
            $product->service_details = $details;
            $product->seo = ['title' => $data['seo_title'] ?? '', 'description' => $data['seo_description'] ?? ''];
            $product->save();
            // Permit SKU swaps while retaining stable variant IDs and order history.
            $product->variants()->update(['sku' => null]);
            foreach ($data['variants'] as $row) {
                $variant = ! empty($row['id']) ? $product->variants()->where('tenant_id', $site->tenant_id)->findOrFail($row['id']) : $product->variants()->make(['tenant_id' => $site->tenant_id]);
                $oldQuantity = (int) $variant->inventory_quantity;
                $variant->fill(['title' => $row['title'], 'sku' => filled($row['sku'] ?? null) ? $row['sku'] : null,
                    'price_cents' => $this->cents($row['price']), 'compare_at_price_cents' => $this->optionalCents($row['compare_at_price'] ?? null),
                    'wholesale_price_cents' => $this->optionalCents($row['wholesale_price'] ?? null),
                    'inventory_quantity' => $product->track_inventory ? (int) ($row['inventory_quantity'] ?? 0) : null,
                    'is_available' => $product->status !== 'archived' && (bool) $row['is_available'],
                ]);
                if (array_key_exists('shipping_weight_ounces', $row)) {
                    $variant->shipping_weight_ounces = $row['shipping_weight_ounces'];
                }
                $variant->save();
                if ($product->track_inventory && $oldQuantity !== $variant->inventory_quantity) {
                    WebsiteInventoryMovement::query()->create(['tenant_id' => $site->tenant_id, 'website_product_variant_id' => $variant->id, 'quantity_delta' => $variant->inventory_quantity - $oldQuantity, 'reason' => 'catalog_adjustment', 'actor_user_id' => $actor->id]);
                }
            }
            $previousIds = $product->collections()->pluck('website_collections.id')->all();
            foreach (array_merge(array_diff($previousIds, $data['collection_ids']), array_diff($data['collection_ids'], $previousIds)) as $id) {
                $collection = WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($id);
                $collection->update(['seo' => array_merge($collection->seo ?? [], ['catalog_revision' => (int) data_get($collection->seo, 'catalog_revision', 0) + 1])]);
            }
            $product->collections()->sync(collect($data['collection_ids'])->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $site->tenant_id]])->all());
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'catalog.product_saved', ['product_id' => $product->id, 'before' => $before, 'after' => $product->fresh(['variants', 'collections'])->toArray()]);

            return $product->fresh(['variants', 'collections']);
        });
    }

    public function cents(string|int|float $value): int
    {
        [$whole,$fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function optionalCents(mixed $value): ?int
    {
        return filled($value) ? $this->cents($value) : null;
    }

    public function publicCatalog(TenantSite $site): array
    {
        return WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'active')
            ->with(['variants' => fn ($q) => $q->where('tenant_id', $site->tenant_id)->orderBy('id'), 'collections' => fn ($q) => $q->where('website_collections.tenant_id', $site->tenant_id)->where('status', 'active')])->orderBy('id')->get()->map(function ($product) {
                $variant = $product->variants->firstWhere('is_available', true) ?? $product->variants->first();

                return ['id' => $product->id, 'slug' => $product->handle, 'name' => $product->title, 'shortName' => $product->title,
                    'summary' => $product->description ?? '', 'image' => $product->media[0] ?? '', 'images' => $product->media ?? [],
                    'alt' => data_get($product->service_details, 'image_alt') ?: $product->title, 'quoteOnly' => true,
                    'details' => preg_split('/\R/', (string) data_get($product->service_details, 'details', ''), -1, PREG_SPLIT_NO_EMPTY),
                    'collection' => $product->collections->first()?->title ?? '', 'collections' => $product->collections->pluck('handle')->all(),
                    'seo' => $product->seo ?? [],
                    'variants' => $product->variants->where('is_available', true)->map(fn ($v) => ['id' => $v->id, 'title' => $v->title, 'available' => ! $product->track_inventory || $v->inventory_quantity > 0])->values()->all(),
                ];
            })->all();
    }

    public function publicCollections(TenantSite $site): array
    {
        return WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'active')->orderBy('title')->get(['title', 'handle', 'description', 'image_url'])->toArray();
    }
}
