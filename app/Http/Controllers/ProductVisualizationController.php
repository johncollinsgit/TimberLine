<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\ProductVisualizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductVisualizationController extends Controller
{
    private function site(Request $request): TenantSite
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return TenantSite::query()->forTenant($tenant)->firstOrFail();
    }

    public function edit(Request $request, WebsiteProduct $product, ProductVisualizationService $visuals)
    {
        $site = $this->site($request);
        $visuals->assertEditor($site, $product, $request->user());

        return response()->view('managed-website.visualization.editor', [
            'site' => $site, 'product' => $product->load('variants'), 'assets' => $visuals->assets($product)->latest('id')->get(),
            'preview' => $visuals->descriptor($site, $product, true),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, WebsiteProduct $product, ProductVisualizationService $visuals)
    {
        $visuals->save($this->site($request), $product, $request->user(), $request->all());

        return back()->with('status', 'Product visualization saved.');
    }

    public function upload(Request $request, WebsiteProduct $product, ProductVisualizationService $visuals)
    {
        $site = $this->site($request);
        $visuals->assertEditor($site, $product, $request->user());
        $request->validate(['model' => ['required', 'file', 'max:30720', 'extensions:glb']]);
        $file = $request->file('model');
        $bytes = file_get_contents($file->getRealPath());
        $visuals->validateGlb($bytes);
        $path = 'tenant-site-models/'.$site->tenant_id.'/'.Str::uuid().'.glb';
        throw_unless(Storage::disk('local')->put($path, $bytes), \RuntimeException::class, 'The model could not be stored.');
        try {
            $media = TenantSiteMedia::query()->create([
                'tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'uploaded_by_user_id' => $request->user()->id,
                'storage_disk' => 'local', 'storage_path' => $path, 'file_name' => Str::limit(basename($file->getClientOriginalName()), 190, ''),
                'mime_type' => 'model/gltf-binary', 'file_size' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
                'kind' => 'model', 'source' => 'visualization:'.$product->id,
            ]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        app(ManagedWebsiteService::class)->recordEvent($site, null, $request->user(), 'catalog.visualization_model_uploaded', ['product_id' => $product->id, 'media_id' => $media->id, 'checksum' => $media->checksum]);

        return back()->with('status', 'Model uploaded. Choose it below and save to use it.');
    }

    public function asset(Request $request, WebsiteProduct $product, TenantSiteMedia $media, ProductVisualizationService $visuals)
    {
        $site = TenantSite::query()->forTenantId($product->tenant_id)->findOrFail($product->tenant_site_id);
        abort_unless($visuals->assets($product)->whereKey($media->id)->exists(), 404);
        $member = $request->user()?->is_active && $visuals->enabledFor($site)
            && app(ManagedWebsiteService::class)->editorEnabledFor($site->tenant)
            && $request->user()->tenants()->whereKey($site->tenant_id)->wherePivot('membership_active', true)
                ->wherePivotIn('role', ['admin', 'owner', 'tenant_owner', 'manager', 'marketing_manager'])->exists();
        $descriptor = $visuals->descriptor($site, $product);
        $urls = $descriptor ? array_merge([$descriptor['modelUrl']], array_column($descriptor['variants'], 'modelUrl')) : [];
        $public = in_array(route('product-visualization.asset', ['product' => $product, 'media' => $media]), $urls, true);
        abort_unless($member || $public, 404);
        abort_unless(Storage::disk($media->storage_disk)->exists($media->storage_path), 404);
        $headers = ['Content-Type' => 'model/gltf-binary', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if ($public) {
            // These bytes are deliberately published. Draft bytes never gain CORS access.
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        return Storage::disk($media->storage_disk)->response($media->storage_path, $media->file_name, $headers, 'inline');
    }
}
