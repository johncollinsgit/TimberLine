<?php

namespace App\Http\Controllers;

use App\Models\FormSubmission;
use App\Models\ReplacementModule;
use App\Models\ShopifyStore;
use App\Models\StockistLocation;
use App\Services\Replacements\ReplacementActivationService;
use App\Services\Replacements\ReplacementOperatorAccessService;
use App\Services\Replacements\ReplacementReadinessService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedShellPayloadBuilder;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopifyReplacementReadinessController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function index(
        Request $request,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        ShopifyEmbeddedShellPayloadBuilder $shell,
        ReplacementReadinessService $readiness
    ): Response {
        $context = $contexts->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $storeContext = (array) ($context['store'] ?? []);
        $tenantId = $authorized ? $tenants->resolveTenantIdForStoreContext($storeContext) : null;
        $store = $tenantId !== null ? $this->contextStore($tenantId, $storeContext) : null;

        $payload = $tenantId !== null && $store
            ? $readiness->dashboard($tenantId, (int) $store->id)
            : null;

        return response()->view('shopify.replacement-readiness', [
            'authorized' => $authorized && $tenantId !== null && $store !== null,
            'shopifyApiKey' => $authorized ? (string) ($storeContext['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($storeContext['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => (string) ($context['host'] ?? ''),
            'storeLabel' => $store ? ucfirst((string) $store->store_key).' Store' : 'Shopify Admin',
            'headline' => 'Replacement Readiness',
            'subheadline' => 'Import, reconcile, test, and stage each app replacement before an isolated cutover.',
            'appNavigation' => $this->embeddedAppNavigation('home', null, $tenantId),
            'pageSubnav' => $shell->dashboardSubnav('replacements', $tenantId, $request),
            'pageActions' => [],
            'readinessPayload' => $payload,
            'readinessEndpoints' => [
                'refresh_base' => url('/shopify/app/api/replacements'),
                'activate_base' => url('/shopify/app/api/replacements'),
            ],
        ], $authorized && $tenantId !== null && $store !== null ? 200 : 401);
    }

    public function show(
        Request $request,
        int $module,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        ShopifyEmbeddedShellPayloadBuilder $shell,
        ReplacementReadinessService $readiness
    ): Response {
        $context = $contexts->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $storeContext = (array) ($context['store'] ?? []);
        $tenantId = $authorized ? $tenants->resolveTenantIdForStoreContext($storeContext) : null;
        $store = $tenantId !== null ? $this->contextStore($tenantId, $storeContext) : null;
        $replacement = $store ? $this->scopedModule($module, $tenantId, (int) $store->id) : null;
        $workspace = null;

        if ($replacement?->module_key === 'storeify_forms') {
            $query = FormSubmission::query()->forTenantId($tenantId)->where('source', 'storeify_import')->with('form:id,name,slug');
            $search = trim((string) $request->query('q', ''));
            if ($search !== '') {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('submitter_name', 'like', '%'.$search.'%')
                        ->orWhere('submitter_email', 'like', '%'.$search.'%')
                        ->orWhere('submitter_company', 'like', '%'.$search.'%');
                });
            }
            $workspace = [
                'kind' => 'storeify_forms',
                'search' => $search,
                'forms' => \App\Models\TenantForm::query()->forTenantId($tenantId)
                    ->whereIn('slug', ['retail-contact', 'job-opportunity', 'legacy-forestry-weekend-2024', 'legacy-storeify-wholesale-application'])
                    ->withCount('submissions')->orderBy('name')->get(),
                'submissions' => $query->latest('submitted_at')->limit(100)->get(),
                'total_submissions' => FormSubmission::query()->forTenantId($tenantId)->where('source', 'storeify_import')->count(),
            ];
        } elseif ($replacement?->module_key === 'omnium_locator') {
            $workspace = [
                'kind' => 'omnium_locator',
                'locations' => StockistLocation::query()->forTenantId($tenantId)->where('shopify_store_id', $store->id)->orderBy('sort_order')->get(),
            ];
        }

        return response()->view('shopify.replacement-workspace', [
            'authorized' => $authorized && $replacement !== null,
            'shopifyApiKey' => $authorized ? (string) ($storeContext['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($storeContext['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => (string) ($context['host'] ?? ''),
            'storeLabel' => $store ? ucfirst((string) $store->store_key).' Store' : 'Shopify Admin',
            'headline' => $replacement ? (string) data_get($replacement->metadata, 'label', \Illuminate\Support\Str::headline($replacement->module_key)) : 'Replacement workspace',
            'subheadline' => 'Inspect imported records and candidate-only administration without changing the live provider.',
            'appNavigation' => $this->embeddedAppNavigation('home', null, $tenantId),
            'pageSubnav' => $shell->dashboardSubnav('replacements', $tenantId, $request),
            'pageActions' => [],
            'modulePayload' => $replacement ? $readiness->modulePayload($replacement->load(['store', 'evidence', 'sourceSnapshots', 'latestImportBatch', 'latestActivationRun']), (int) $store->id) : null,
            'workspace' => $workspace,
            'workflowEndpointBase' => url('/shopify/app/api/replacements/submissions'),
            'exportEndpoint' => $replacement ? url('/shopify/app/api/replacements/'.$replacement->id.'/submissions.csv') : null,
        ], $authorized && $replacement !== null ? 200 : 401);
    }

    public function refresh(
        Request $request,
        int $module,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        ReplacementOperatorAccessService $access,
        ReplacementReadinessService $readiness
    ): JsonResponse {
        [$context, $tenantId, $store, $actor] = $this->mutationContext($request, $contexts, $tenants, $access);
        if (! $actor || ! $store) {
            return response()->json(['ok' => false, 'message' => 'Owner or admin access could not be verified.'], 403);
        }

        $replacement = $this->scopedModule($module, $tenantId, (int) $store->id);
        $replacement = $readiness->refreshStatus($replacement);
        $readiness->recordEvidence($replacement, 'operation', 'gate_recheck', 'verified', [
            'message' => 'Readiness gates recalculated from current evidence.',
            'store_id' => (int) $store->id,
        ], $this->actorReference($context, $actor->email));

        return response()->json([
            'ok' => true,
            'message' => 'Readiness gates recalculated.',
            'data' => $readiness->modulePayload($replacement->fresh(['store', 'evidence', 'sourceSnapshots', 'latestImportBatch', 'latestActivationRun']), (int) $store->id),
        ]);
    }

    public function activate(
        Request $request,
        int $module,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        ReplacementOperatorAccessService $access,
        ReplacementActivationService $activations
    ): JsonResponse {
        [$context, $tenantId, $store, $actor] = $this->mutationContext($request, $contexts, $tenants, $access);
        if (! $actor || ! $store || ! $access->activationIdentityAllowed($request, $context, $actor)) {
            return response()->json(['ok' => false, 'message' => 'This activation requires an allowlisted owner identity.'], 403);
        }

        $replacement = $this->scopedModule($module, $tenantId, (int) $store->id);
        if ($replacement->module_key === 'recharge') {
            return response()->json(['ok' => false, 'message' => 'Recharge activation is protected until a proven controlled renewal pilot is separately approved.'], 423);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $run = $activations->activate(
                $replacement,
                $actor,
                $this->actorReference($context, $actor->email),
                (string) $validated['idempotency_key']
            );
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Replacement activated and smoke checks passed.', 'data' => $run]);
    }

    public function updateSubmission(
        Request $request,
        int $submission,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        ReplacementOperatorAccessService $access
    ): JsonResponse {
        [$context, $tenantId, $store, $actor] = $this->mutationContext($request, $contexts, $tenants, $access);
        if (! $actor || ! $store) {
            return response()->json(['ok' => false, 'message' => 'Owner or admin access could not be verified.'], 403);
        }
        $record = FormSubmission::query()->forTenantId($tenantId)->where('source', 'storeify_import')->findOrFail($submission);
        $validated = $request->validate([
            'status' => ['required', 'in:submitted,in_review,resolved,archived'],
            'assigned_to' => ['nullable', 'email:rfc', 'max:190'],
        ]);
        $metadata = (array) $record->metadata;
        $history = (array) ($metadata['workflow_history'] ?? []);
        $history[] = [
            'at' => now()->toIso8601String(),
            'actor' => $this->actorReference($context, $actor->email),
            'status' => $validated['status'],
            'assigned_to' => $validated['assigned_to'] ?? null,
        ];
        $metadata['assigned_to'] = $validated['assigned_to'] ?? null;
        $metadata['workflow_history'] = array_slice($history, -100);
        $record->forceFill(['status' => $validated['status'], 'metadata' => $metadata])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Submission workflow saved.',
            'data' => ['id' => $record->id, 'status' => $record->status, 'assigned_to' => $metadata['assigned_to']],
        ]);
    }

    public function exportSubmissions(
        Request $request,
        int $module,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        ReplacementOperatorAccessService $access
    ): StreamedResponse|JsonResponse {
        [, $tenantId, $store, $actor] = $this->mutationContext($request, $contexts, $tenants, $access);
        if (! $actor || ! $store) {
            return response()->json(['ok' => false, 'message' => 'Owner or admin access could not be verified.'], 403);
        }
        $replacement = $this->scopedModule($module, $tenantId, (int) $store->id);
        if ($replacement->module_key !== 'storeify_forms') {
            return response()->json(['ok' => false, 'message' => 'Submission export is available only for the forms replacement.'], 422);
        }

        return response()->streamDownload(function () use ($tenantId): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['id', 'form', 'status', 'submitted_at', 'name', 'email', 'phone', 'company', 'assigned_to', 'source_key']);
            FormSubmission::query()->forTenantId($tenantId)->where('source', 'storeify_import')->with('form:id,name')->orderBy('id')->chunkById(250, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->id,
                        $row->form?->name,
                        $row->status,
                        $row->submitted_at?->toIso8601String(),
                        $row->submitter_name,
                        $row->submitter_email,
                        $row->submitter_phone,
                        $row->submitter_company,
                        data_get($row->metadata, 'assigned_to'),
                        $row->source_key,
                    ]);
                }
            });
            fclose($handle);
        }, 'everbranch-storeify-submissions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0:array<string,mixed>,1:int,2:?ShopifyStore,3:mixed} */
    private function mutationContext(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, ReplacementOperatorAccessService $access): array
    {
        $context = $contexts->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return [$context, 0, null, null];
        }

        $storeContext = (array) ($context['store'] ?? []);
        $tenantId = (int) ($tenants->resolveTenantIdForStoreContext($storeContext) ?? 0);
        $store = $tenantId > 0 ? $this->contextStore($tenantId, $storeContext) : null;

        return [$context, $tenantId, $store, $tenantId > 0 ? $access->resolveActor($request, $context, $tenantId) : null];
    }

    private function contextStore(int $tenantId, array $storeContext): ?ShopifyStore
    {
        $key = strtolower(trim((string) ($storeContext['key'] ?? '')));
        $shop = strtolower(trim((string) ($storeContext['shop'] ?? '')));

        return ShopifyStore::query()->forTenantId($tenantId)
            ->where(function ($query) use ($key, $shop): void {
                if ($key !== '') {
                    $query->where('store_key', $key);
                }
                if ($shop !== '') {
                    $key !== '' ? $query->orWhere('shop_domain', $shop) : $query->where('shop_domain', $shop);
                }
            })->first();
    }

    private function scopedModule(int $id, int $tenantId, int $storeId): ReplacementModule
    {
        return ReplacementModule::query()->forTenantId($tenantId)
            ->where('shopify_store_id', $storeId)
            ->findOrFail($id);
    }

    private function actorReference(array $context, ?string $fallback): string
    {
        return trim((string) ($context['shopify_admin_email'] ?? $fallback ?? 'shopify-owner')) ?: 'shopify-owner';
    }
}
