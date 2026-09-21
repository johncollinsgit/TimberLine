<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
use App\Models\WebsiteCollection;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteCatalogController extends Controller
{
    private function site(Request $request): TenantSite
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return TenantSite::query()->forTenant($tenant)->firstOrFail();
    }

    private function product(TenantSite $site, WebsiteProduct $product): WebsiteProduct
    {
        abort_unless($product->tenant_id === $site->tenant_id && $product->tenant_site_id === $site->id, 404);

        return $product->load('variants', 'collections');
    }

    public function index(Request $request)
    {
        $site = $this->site($request);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:190'], 'status' => ['nullable', Rule::in(['active', 'draft', 'archived', 'all'])], 'collection' => ['nullable', 'integer'], 'sort' => ['nullable', Rule::in(['title', 'newest', 'updated'])]]);
        $query = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->with('variants', 'collections');
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (filled($filters['q'] ?? null)) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('handle', 'like', $like)->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)));
        }
        if (! empty($filters['collection'])) {
            $query->whereHas('collections', fn ($q) => $q->where('website_collections.id', $filters['collection'])->where('website_collections.tenant_id', $site->tenant_id));
        }
        match ($filters['sort'] ?? 'title') {
            'newest' => $query->latest('id'),'updated' => $query->latest('updated_at'),default => $query->orderBy('title')
        };
        $counts = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('managed-website.catalog.products', ['site' => $site, 'products' => $query->paginate(30)->withQueryString(), 'filters' => $filters, 'status' => $status, 'counts' => $counts, 'collections' => $this->collectionsFor($site), 'canEdit' => app(ManagedWebsiteService::class)->editorEnabledFor($site->tenant)]);
    }

    private function collectionsFor(TenantSite $site)
    {
        return WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->orderBy('title')->get();
    }

    public function create(Request $request, WebsiteCatalogService $catalog)
    {
        $site = $this->site($request);
        $catalog->assertEditor($site, $request->user());

        return view('managed-website.catalog.product', ['site' => $site, 'product' => null, 'collections' => $this->collectionsFor($site)]);
    }

    public function edit(Request $request, WebsiteProduct $product, WebsiteCatalogService $catalog)
    {
        $site = $this->site($request);
        $catalog->assertEditor($site, $request->user());

        return view('managed-website.catalog.product', ['site' => $site, 'product' => $this->product($site, $product), 'collections' => $this->collectionsFor($site)]);
    }

    public function store(Request $request, WebsiteCatalogService $catalog)
    {
        return $this->save($request, $catalog, null);
    }

    public function update(Request $request, WebsiteProduct $product, WebsiteCatalogService $catalog)
    {
        return $this->save($request, $catalog, $product);
    }

    private function save(Request $request, WebsiteCatalogService $catalog, ?WebsiteProduct $product)
    {
        $site = $this->site($request);
        $catalog->assertEditor($site, $request->user());
        if ($product) {
            $this->product($site, $product);
        }
        $request->validate(['images' => ['nullable', 'array', 'max:12'], 'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,avif', 'max:10240'], 'image_urls' => ['nullable', 'string', 'max:25000'], 'retained_media' => ['nullable', 'array', 'max:12'], 'retained_media.*' => ['url:http,https', 'max:2048']]);
        $data = $request->except(['images', '_token', '_method']);
        $data['handle'] = $data['handle'] ?? $product?->handle ?? Str::slug($data['title'] ?? '');
        $data['track_inventory'] = $request->boolean('track_inventory');
        $data['collection_ids'] = $request->input('collection_ids', []);
        $data['media'] = array_merge($request->input('retained_media', []), array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $request->input('image_urls', ''))))));
        if (! $request->has('catalog_form')) {
            $data['media'] = filled($request->input('image_url')) ? [$request->input('image_url')] : ($product?->media ?? []);
            $data['collection_ids'] = $product?->collections->pluck('id')->all() ?? [];
            $data['variants'] = [['id' => $product?->variants->first()?->id, 'title' => $data['variant_title'] ?? 'Default', 'sku' => $data['sku'] ?? null, 'price' => $data['price'] ?? '0', 'wholesale_price' => $data['wholesale_price'] ?? null, 'compare_at_price' => $data['compare_at_price'] ?? null, 'inventory_quantity' => $data['inventory_quantity'] ?? 0, 'is_available' => $request->boolean('is_available', true)]];
        }
        $stored = [];
        try {
            $saved = DB::transaction(function () use ($request, $site, $catalog, $product, &$data, &$stored) {
                foreach ($request->file('images', []) as $file) {
                    $path = 'tenant-site-media/'.$site->tenant_id.'/'.Str::uuid().'.'.$file->extension();
                    throw_unless(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), \RuntimeException::class, 'The product image could not be stored.');
                    $stored[] = $path;
                    $media = TenantSiteMedia::query()->create(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'uploaded_by_user_id' => $request->user()->id, 'storage_disk' => 'local', 'storage_path' => $path, 'file_name' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()), 'kind' => 'image', 'source' => 'upload']);
                    $data['media'][] = route('managed-website.catalog.media', $media);
                }

                return $catalog->saveProduct($site, $product, $data, $request->user());
            });
        } catch (\Throwable $error) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return redirect()->route('managed-website.products.edit', $saved)->with('status', 'Product saved.');
    }

    public function archive(Request $request, WebsiteProduct $product, WebsiteCatalogService $catalog)
    {
        $site = $this->site($request);
        $catalog->assertEditor($site, $request->user());
        $this->product($site, $product);
        abort_unless(app(ManagedWebsiteService::class)->publishingEnabled(), 423);
        DB::transaction(function () use ($site, $product, $request) {
            $product = WebsiteProduct::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($product->id);
            $before = $product->toArray();
            $details = $product->service_details ?? [];
            $details['catalog_revision'] = (int) ($details['catalog_revision'] ?? 0) + 1;
            $product->update(['status' => 'archived', 'service_details' => $details]);
            $product->variants()->update(['is_available' => false]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $request->user(), 'catalog.product_archived', ['product_id' => $product->id, 'before' => $before]);
        });

        return redirect()->route('managed-website.products.index', ['status' => 'archived'])->with('status', 'Product archived. Order history is preserved.');
    }

    public function collections(Request $request)
    {
        $site = $this->site($request);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:190']])['q'] ?? '';
        $collections = WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->withCount('products')
            ->when($q, fn ($query) => $query->where('title', 'like', '%'.addcslashes($q, '%_\\').'%'))->orderBy('title')->paginate(30)->withQueryString();

        return view('managed-website.catalog.collections', compact('site', 'collections', 'q'));
    }

    public function createCollection(Request $request, WebsiteCatalogService $catalog)
    {
        return $this->collectionEditor($request, $catalog, null);
    }

    public function editCollection(Request $request, WebsiteCollection $collection, WebsiteCatalogService $catalog)
    {
        return $this->collectionEditor($request, $catalog, $collection);
    }

    private function collectionEditor(Request $request, WebsiteCatalogService $catalog, ?WebsiteCollection $collection)
    {
        $site = $this->site($request);
        $catalog->assertEditor($site, $request->user());
        if ($collection) {
            abort_unless($collection->tenant_id === $site->tenant_id && $collection->tenant_site_id === $site->id, 404);
            $collection->load('products');
        }
        $products = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->orderBy('title')->get();

        return view('managed-website.catalog.collection', compact('site', 'collection', 'products'));
    }

    public function storeCollection(Request $request, WebsiteCatalogService $catalog)
    {
        return $this->saveCollection($request, $catalog, null);
    }

    public function updateCollection(Request $request, WebsiteCollection $collection, WebsiteCatalogService $catalog)
    {
        return $this->saveCollection($request, $catalog, $collection);
    }

    private function saveCollection(Request $request, WebsiteCatalogService $catalog, ?WebsiteCollection $collection)
    {
        $site = $this->site($request);
        $catalog->assertEditor($site, $request->user());
        if ($collection) {
            abort_unless($collection->tenant_id === $site->tenant_id && $collection->tenant_site_id === $site->id, 404);
        }
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'handle' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('website_collections', 'handle')->where('tenant_site_id', $site->id)->ignore($collection?->id)], 'description' => ['nullable', 'string', 'max:8000'], 'status' => ['required', Rule::in(['draft', 'active', 'archived'])], 'image_url' => ['nullable', 'url:http,https', 'max:2048'], 'product_ids' => ['nullable', 'array', 'max:500'], 'product_ids.*' => ['integer', 'distinct', Rule::exists('website_products', 'id')->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)], 'revision' => ['nullable', 'integer', 'min:0']]);
        if (($collection?->status === 'active') || $data['status'] === 'active') {
            abort_unless(app(ManagedWebsiteService::class)->publishingEnabled(), 423);
        }
        $saved = DB::transaction(function () use ($site, $collection, $data, $request) {
            TenantSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            if ($collection) {
                $collection = WebsiteCollection::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($collection->id);
                abort_unless((int) ($data['revision'] ?? -1) === (int) data_get($collection->seo, 'catalog_revision', 0), 409, 'This collection changed. Reload before saving.');
            }
            $before = $collection?->load('products')->toArray();
            $collection ??= new WebsiteCollection(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id]);
            $collection->fill(collect($data)->only(['title', 'handle', 'description', 'status', 'image_url'])->all());
            $collection->seo = array_merge($collection->seo ?? [], ['catalog_revision' => (int) data_get($collection->seo, 'catalog_revision', 0) + 1]);
            $collection->save();
            $previousIds = $collection->products()->pluck('website_products.id')->all();
            $nextIds = $data['product_ids'] ?? [];
            foreach (array_merge(array_diff($previousIds, $nextIds), array_diff($nextIds, $previousIds)) as $id) {
                $member = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($id);
                $member->update(['service_details' => array_merge($member->service_details ?? [], ['catalog_revision' => (int) data_get($member->service_details, 'catalog_revision', 0) + 1])]);
            }
            $collection->products()->sync(collect($data['product_ids'] ?? [])->mapWithKeys(fn ($id, $position) => [$id => ['tenant_id' => $site->tenant_id, 'position' => $position]])->all());
            app(ManagedWebsiteService::class)->recordEvent($site, null, $request->user(), 'catalog.collection_saved', ['collection_id' => $collection->id, 'before' => $before, 'after' => $collection->fresh('products')->toArray()]);

            return $collection;
        });

        return redirect()->route('managed-website.collections.edit', $saved)->with('status', 'Collection saved.');
    }

    public function media(Request $request, TenantSiteMedia $media, ManagedWebsiteService $websites)
    {
        $site = TenantSite::query()->forTenantId($media->tenant_id)->findOrFail($media->tenant_site_id);
        $member = $request->user()?->is_active && $websites->editorEnabledFor($site->tenant) && $request->user()->tenants()->whereKey($site->tenant_id)->wherePivot('membership_active', true)->wherePivotIn('role', ['admin', 'owner', 'tenant_owner', 'manager', 'marketing_manager'])->exists();
        $url = route('managed-website.catalog.media', $media);
        $public = in_array((int) $site->tenant_id, (array) config('managed_website.editor_tenant_ids', []), true) && $site->public_enabled && $site->status === 'published' && $websites->publicRenderingEnabled()
            && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($site->tenant_id, 'managed_website')
            && WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'active')->whereJsonContains('media', $url)->exists();
        abort_unless($member || $public, 404);
        abort_unless(Storage::disk($media->storage_disk)->exists($media->storage_path), 404);

        return Storage::disk($media->storage_disk)->response($media->storage_path, $media->file_name, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
