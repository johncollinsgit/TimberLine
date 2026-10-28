<?php

namespace App\Http\Controllers\Wholesale;

use App\Http\Controllers\ShopifyEmbeddedAppController;
use App\Jobs\Wholesale\ProcessWholesaleLabel;
use App\Models\Order;
use App\Models\WholesaleLabelAssignment;
use App\Models\WholesaleLabelDesign;
use App\Models\WholesaleLabelRequest;
use App\Models\WholesaleLabelRevision;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use App\Services\Wholesale\Labels\WholesaleLabelContext;
use App\Services\Wholesale\Labels\WholesaleLabelLibrary;
use App\Services\Wholesale\Labels\WholesaleLabelProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WholesaleLabelAdminController extends ShopifyEmbeddedAppController
{
    private function labelStaff(Request $request, bool $mutation = false): array
    {
        $service = app(ShopifyEmbeddedAppContext::class);
        $context = ($mutation || $request->bearerToken()) ? $service->resolveMutationContext($request) : $service->resolvePageContext($request);
        abort_unless($context['ok'] ?? false, 401, 'Open Custom Labels from MF Wholesale Backstage.');
        $tenant = $this->resolvedMappedWholesaleTenantId($context, app(TenantResolver::class));
        abort_unless($tenant && app(TenantModuleAccessResolver::class)->canAccess($tenant, 'wholesale_operations'), 403);
        if (empty($context['shopify_admin_email']) && ($context['auth_source'] ?? '') === 'session_token') {
            $token = $request->bearerToken() ?: $request->input('shopify_session_token', '');
            $context['shopify_admin_email'] = app(\App\Services\Shopify\ShopifyAdminIdentityResolver::class)->emailForVerifiedSession($context, (string) $token);
        }
        $actor = $this->resolveWholesaleWorkspaceActor($context, $tenant);
        abort_unless(app(WholesaleLabelContext::class)->canManage($actor, $tenant), 403, 'Custom label manager access is required.');

        return [$tenant, $context, $actor];
    }

    public function index(Request $request, WholesaleLabelLibrary $library)
    {
        if (! auth()->check() && ! $request->bearerToken()) {
            $resolved = $this->resolveWholesaleWorkspaceContext($request, app(ShopifyEmbeddedAppContext::class));
            $tenant = $this->resolvedWholesaleTenantId($resolved, app(TenantResolver::class));
            abort_unless($tenant, 403);

            return $this->wholesaleWorkspaceResponse($resolved, $tenant, 'labels', 'Custom Labels', 'Verifying artwork manager access.', 'shopify.wholesale-labels-bootstrap');
        }
        [$tenant, $context, $actor] = $this->labelStaff($request);
        $search = trim((string) $request->query('search', ''));
        $designs = WholesaleLabelDesign::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain']);
        if ($search !== '') {
            $customerIds = Order::query()->where('tenant_id', $tenant)->where('shopify_store_key', 'wholesale')->where(function ($q) use ($search) {
                foreach (['first_name', 'last_name', 'email', 'shipping_company', 'billing_company'] as $column) {
                    $q->orWhere($column, 'like', '%'.substr($search, 0, 120).'%');
                }
            })->whereNotNull('shopify_customer_id')->distinct()->pluck('shopify_customer_id');
            $designs->where(fn ($q) => $q->where('name', 'like', '%'.substr($search, 0, 120).'%')->orWhere('customer_id', $search)->orWhereIn('customer_id', $customerIds));
        }
        if ($request->query('customer_id')) {
            $designs->where('customer_id', (string) $request->query('customer_id'));
        }
        $designs = $designs->orderByDesc('updated_at')->limit(100)->get();
        $revisions = WholesaleLabelRevision::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain'])->whereIn('design_id', $designs->pluck('id'))->orderByDesc('created_at')->get()->groupBy('design_id');
        $pending = WholesaleLabelRequest::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain'])->where('mode', 'legacy')->whereNull('revision_id')->orderByDesc('created_at')->limit(100)->get();
        $assignments = WholesaleLabelAssignment::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain'])->orderByDesc('created_at')->limit(250)->get();
        $customers = Order::query()->where('tenant_id', $tenant)->where('shopify_store_key', 'wholesale')->whereNotNull('shopify_customer_id')->orderByDesc('ordered_at')->limit(1000)->get()->unique('shopify_customer_id');
        $resolved = $this->resolveWholesaleWorkspaceContext($request, app(ShopifyEmbeddedAppContext::class));

        return $this->wholesaleWorkspaceResponse($resolved, $tenant, 'labels', 'Custom Labels', 'Customer artwork, original uploads, vector proofs, and production review.', 'shopify.wholesale-labels', compact('designs', 'revisions', 'pending', 'assignments', 'customers', 'search') + ['events' => \App\Models\WholesaleLabelEvent::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain'])->whereIn('design_id', $designs->pluck('id'))->orderByDesc('created_at')->limit(500)->get()->groupBy('design_id')] + ['contextToken' => app(ShopifyEmbeddedAppContext::class)->issueContextToken($context)]);
    }

    public function import(Request $request, WholesaleLabelLibrary $library)
    {
        [$tenant, $context, $actor] = $this->labelStaff($request, true);
        $data = $request->validate(['customer_id' => ['required', 'regex:/^[0-9]{1,20}$/'], 'name' => ['nullable', 'string', 'max:120'], 'files' => ['required', 'array', 'min:1', $request->input('request_id') ? 'max:1' : 'max:50'], 'files.*' => ['required', 'file', 'max:20480'], 'confirmed' => ['accepted'], 'request_id' => ['nullable', 'uuid']]);
        $store = ShopifyStores::findByShopDomain($context['shop_domain']);
        $customer = (new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']))->query('query LabelImportCustomer($id: ID!) { customer(id: $id) { id tags } }', ['id' => 'gid://shopify/Customer/'.$data['customer_id']]);
        abort_unless($customer['customer'] ?? null, 422, 'Choose a customer in this Shopify store.');
        $owner = ['tenant_id' => $tenant, 'shop_domain' => $context['shop_domain'], 'customer_id' => $data['customer_id']];
        $pending = ($data['request_id'] ?? null) ? $library->owned(WholesaleLabelRequest::class, $owner)->where('mode', 'legacy')->whereNull('revision_id')->findOrFail($data['request_id']) : null;
        $results = [];
        foreach ($request->file('files') as $file) {
            try {
                $revision = $library->upload($owner, $file, ($data['name'] ?? null) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), null, $actor->id);
                if ($data['request_id'] ?? null) {
                    $pending->update(['revision_id' => $revision->id, 'design_id' => $revision->design_id]);
                    $library->event($owner, 'legacy_located', ['request_id' => $pending->id, 'revision_id' => $revision->id, 'design_id' => $revision->design_id], $actor->id);
                }
                $results[] = ['file' => $file->getClientOriginalName(), 'id' => $revision->id, 'ok' => true];
            } catch (\Throwable $e) {
                $results[] = ['file' => $file->getClientOriginalName(), 'ok' => false, 'error' => $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : 'This file could not be saved. Please retry it.'];
            }
        }

        return response()->json(['results' => $results])->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request, string $revision, WholesaleLabelLibrary $library)
    {
        [$tenant, $context, $actor] = $this->labelStaff($request, true);
        $record = WholesaleLabelRevision::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain'])->findOrFail($revision);
        $owner = ['tenant_id' => $tenant, 'shop_domain' => $record->shop_domain, 'customer_id' => $record->customer_id];
        $data = $request->validate(['action' => ['required', 'in:approve,regenerate,archive'], 'threshold' => ['nullable', 'integer', 'min:1', 'max:254'], 'crop' => ['nullable', 'array:x,y,width,height'], 'crop.x' => ['required_with:crop', 'numeric', 'between:0,0.99'], 'crop.y' => ['required_with:crop', 'numeric', 'between:0,0.99'], 'crop.width' => ['required_with:crop', 'numeric', 'between:0.01,1'], 'crop.height' => ['required_with:crop', 'numeric', 'between:0.01,1'], 'vector' => ['nullable', 'file', 'max:20480']]);
        if ($data['action'] === 'approve') {
            $library->approve($owner, $record->id, $actor->id);
        } elseif ($data['action'] === 'archive') {
            $library->owned(WholesaleLabelDesign::class, $owner)->findOrFail($record->design_id)->update(['archived_at' => now()]);
            $library->event($owner, 'archived', ['design_id' => $record->design_id], $actor->id);
        } else {
            $settings = ['threshold' => (int) ($data['threshold'] ?? 160)];
            if (! empty($data['crop'])) {
                $settings['crop'] = array_map('floatval', $data['crop']);
            }
            $new = $record->replicate(['approved_at', 'approved_by', 'preview_path', 'vector_path', 'vector_preview_path', 'vector_sha256', 'failure']);
            $new->id = (string) Str::uuid();
            $new->status = 'queued';
            $new->created_by = $actor->id;
            $new->source = 'staff_revision';
            if ($request->hasFile('vector')) {
                $svg = app(WholesaleLabelProcessor::class)->safeSvg(file_get_contents($request->file('vector')->getRealPath()), true);
                $settings['replacement_path'] = 'wholesale-labels/'.$tenant.'/'.$new->id.'/replacement.svg';
                Storage::disk(config('wholesale_labels.disk'))->put($settings['replacement_path'], $svg, ['visibility' => 'private']);
            }
            $new->settings = $settings;
            $new->save();
            $library->event($owner, 'revision_created', ['design_id' => $record->design_id, 'revision_id' => $new->id], $actor->id, ['source_revision_id' => $record->id]);
            ProcessWholesaleLabel::dispatch($new->id);
        }

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }

    public function asset(Request $request, string $revision, string $kind)
    {
        [$tenant, $context] = $this->labelStaff($request);
        $record = WholesaleLabelRevision::query()->forAllTenants()->where('tenant_id', $tenant)->where('shop_domain', $context['shop_domain'])->findOrFail($revision);
        $path = match ($kind) {
            'original' => $record->original_path, 'vector' => $record->vector_path, 'preview' => $record->preview_path, 'vector-preview' => $record->vector_preview_path, default => null
        };
        $disk = Storage::disk(config('wholesale_labels.disk'));
        abort_unless($path && $disk->exists($path), 404);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (in_array($kind, ['preview', 'vector-preview'], true)) {
            return response($disk->get($path), 200, $headers + ['Content-Type' => 'image/png']);
        }

        return $disk->download($path, $kind === 'vector' ? 'label-'.$record->id.'.svg' : $record->original_name, $headers + ['Content-Type' => 'application/octet-stream']);
    }
}
