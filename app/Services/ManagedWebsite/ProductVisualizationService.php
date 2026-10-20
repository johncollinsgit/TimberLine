<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductVisualizationService
{
    public function enabledFor(TenantSite $site): bool
    {
        return (bool) config('product_visualization.enabled', false)
            && in_array((int) $site->tenant_id, (array) config('product_visualization.tenant_ids', []), true)
            && in_array((int) $site->tenant_id, (array) config('managed_website.editor_tenant_ids', []), true)
            && app(TenantModuleAccessResolver::class)->canAccess($site->tenant_id, 'managed_website');
    }

    public function assertEditor(TenantSite $site, WebsiteProduct $product, User $actor): void
    {
        abort_unless($product->tenant_id === $site->tenant_id && (int) $product->tenant_site_id === (int) $site->id, 404);
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        abort_unless($this->enabledFor($site), 404);
    }

    public function assets(WebsiteProduct $product)
    {
        return TenantSiteMedia::query()->forTenantId($product->tenant_id)
            ->where('tenant_site_id', $product->tenant_site_id)->where('kind', 'model')
            ->where('source', 'visualization:'.$product->id);
    }

    public function save(TenantSite $site, WebsiteProduct $product, User $actor, array $input): WebsiteProduct
    {
        $this->assertEditor($site, $product, $actor);
        $data = Validator::make($input, [
            'revision' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean'],
            'placement' => ['required', Rule::in(['hand', 'room'])],
            'model_media_id' => ['nullable', 'integer', Rule::exists('tenant_site_media', 'id')->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('source', 'visualization:'.$product->id)->where('kind', 'model')],
            'length_mm' => ['required', 'numeric', 'between:1,10000'],
            'dimensions_confirmed' => ['required', 'boolean'],
            'grip_x' => ['required', 'numeric', 'between:0,1'], 'grip_y' => ['required', 'numeric', 'between:0,1'], 'grip_z' => ['required', 'numeric', 'between:0,1'],
            'rotation_x' => ['required', 'numeric', 'between:-180,180'], 'rotation_y' => ['required', 'numeric', 'between:-180,180'], 'rotation_z' => ['required', 'numeric', 'between:-180,180'],
            'variant_models' => ['sometimes', 'array', 'max:100'],
            'variant_models.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('website_product_variants', 'id')->where('tenant_id', $site->tenant_id)->where('website_product_id', $product->id)],
            'variant_models.*.media_id' => ['nullable', 'integer', Rule::exists('tenant_site_media', 'id')->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('source', 'visualization:'.$product->id)->where('kind', 'model')],
        ])->validate();
        if ($data['enabled'] && (! $data['model_media_id'] || ! $data['dimensions_confirmed'] || $product->product_type !== 'physical')) {
            throw ValidationException::withMessages(['enabled' => 'Choose a complete 3D model and confirm the dimensions for this physical product before enabling its preview.']);
        }
        if ($data['enabled'] && $data['placement'] === 'hand' && ! config('product_visualization.hand_enabled')) {
            throw ValidationException::withMessages(['placement' => 'Live hand preview has not been enabled for this installation.']);
        }

        return DB::transaction(function () use ($site, $product, $actor, $data) {
            TenantSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $saved = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($product->id);
            abort_unless((int) data_get($saved->service_details, 'catalog_revision', 0) === (int) $data['revision'], 409, 'This product changed. Reload before saving.');
            abort_unless($saved->status !== 'active' || app(ManagedWebsiteService::class)->publishingEnabled(), 423);
            $details = $saved->service_details ?? [];
            $before = $details['visualization'] ?? null;
            $details['visualization'] = array_diff_key($data, ['revision' => true]);
            $details['catalog_revision'] = (int) ($details['catalog_revision'] ?? 0) + 1;
            $saved->update(['service_details' => $details]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'catalog.visualization_saved', ['product_id' => $saved->id, 'before' => $before, 'after' => $details['visualization']]);

            return $saved;
        });
    }

    public function descriptor(TenantSite $site, WebsiteProduct $product, bool $editor = false): ?array
    {
        $config = data_get($product->service_details, 'visualization', []);
        if (! is_array($config) || ! ($config['enabled'] ?? false) || ! ($config['dimensions_confirmed'] ?? false)) {
            return null;
        }
        if (! $this->enabledFor($site) || (int) $product->tenant_site_id !== (int) $site->id || $product->tenant_id !== $site->tenant_id || $product->product_type !== 'physical') {
            return null;
        }
        if (! $editor && ($product->status !== 'active' || ! $site->public_enabled || $site->status !== 'published' || ! app(ManagedWebsiteService::class)->publicRenderingEnabled())) {
            return null;
        }
        if (($config['placement'] ?? '') === 'hand' && ! config('product_visualization.hand_enabled')) {
            return null;
        }
        $assets = $this->assets($product)->get()->keyBy('id');
        if (! $assets->has($config['model_media_id'] ?? 0)) {
            return null;
        }
        $url = fn ($id) => route('product-visualization.asset', ['product' => $product->id, 'media' => $id]);
        $overrides = collect($config['variant_models'] ?? [])->keyBy('variant_id');
        $variants = $product->variants()->where('tenant_id', $site->tenant_id)->where('is_available', true)->get()->map(function ($variant) use ($overrides, $assets, $url, $config) {
            $override = data_get($overrides->get($variant->id), 'media_id');

            return ['id' => $variant->id, 'label' => $variant->title, 'modelUrl' => $url($assets->has($override) ? $override : $config['model_media_id'])];
        })->all();

        return ['version' => 1, 'productId' => $product->id, 'title' => $product->title,
            'viewerModuleUrl' => asset('product-visualization/viewer.js'), 'viewerStyleUrl' => asset('product-visualization/viewer.css'),
            'placement' => $config['placement'], 'modelUrl' => $url($config['model_media_id']),
            'lengthMm' => (float) $config['length_mm'], 'grip' => array_map(fn ($axis) => (float) ($config['grip_'.$axis] ?? .5), ['x', 'y', 'z']),
            'rotationDeg' => array_map(fn ($axis) => (float) ($config['rotation_'.$axis] ?? 0), ['x', 'y', 'z']),
            'variants' => $variants,
        ];
    }

    /** Only self-contained, uncompressed glTF 2.0 binary files enter this viewer. */
    public function validateGlb(string $bytes): void
    {
        $fail = fn () => throw ValidationException::withMessages(['model' => 'Use a complete, self-contained GLB 2.0 model without external files or required compression, under 30 MB.']);
        if (strlen($bytes) < 24 || strlen($bytes) > 30 * 1024 * 1024) {
            $fail();
        }
        $header = unpack('Vmagic/Vversion/Vlength', substr($bytes, 0, 12));
        if ($header['magic'] !== 0x46546C67 || $header['version'] !== 2 || $header['length'] !== strlen($bytes)) {
            $fail();
        }
        $offset = 12;
        $document = null;
        while ($offset < strlen($bytes)) {
            if ($offset + 8 > strlen($bytes)) {
                $fail();
            }
            $chunk = unpack('Vlength/Vtype', substr($bytes, $offset, 8));
            if ($chunk['length'] % 4 !== 0 || $offset + 8 + $chunk['length'] > strlen($bytes)) {
                $fail();
            }
            if ($offset === 12) {
                if ($chunk['type'] !== 0x4E4F534A) {
                    $fail();
                }
                $document = json_decode(substr($bytes, $offset + 8, $chunk['length']), true);
            }
            $offset += 8 + $chunk['length'];
        }
        if (! is_array($document) || data_get($document, 'asset.version') !== '2.0' || empty($document['meshes']) || ! empty($document['extensionsRequired'])) {
            $fail();
        }
        foreach (['buffers', 'images', 'accessors', 'extensionsUsed'] as $key) {
            if (isset($document[$key]) && ! is_array($document[$key])) {
                $fail();
            }
        }
        foreach (array_merge($document['buffers'] ?? [], $document['images'] ?? []) as $resource) {
            if (! is_array($resource) || array_key_exists('uri', $resource)) {
                $fail();
            }
        }
        foreach ($document['extensionsUsed'] ?? [] as $extension) {
            if (in_array($extension, ['KHR_draco_mesh_compression', 'EXT_meshopt_compression', 'KHR_texture_basisu'], true)) {
                $fail();
            }
        }
        if (collect($document['accessors'] ?? [])->sum('count') > 2000000) {
            $fail();
        }
    }
}
