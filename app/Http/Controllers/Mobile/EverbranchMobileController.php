<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobPhoto;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\StripeHostedBillingService;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EverbranchMobileController extends Controller
{
    public function workspaces(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'user' => $this->userPayload($user),
            'workspaces' => $user->tenants()
                ->orderBy('tenants.name')
                ->get(['tenants.id', 'tenants.name', 'tenants.slug'])
                ->map(fn (Tenant $tenant): array => [
                    'id' => (int) $tenant->id,
                    'name' => (string) $tenant->name,
                    'slug' => (string) $tenant->slug,
                    'role' => (string) ($tenant->pivot->role ?? $user->role ?? 'member'),
                ])
                ->values(),
        ]);
    }

    public function bootstrap(
        Request $request,
        TenantExperienceProfileService $experienceProfiles,
        UnifiedDashboardService $dashboard,
        TenantMobileModuleRegistry $mobileModules,
        TenantModuleCatalogService $catalog
    ): JsonResponse {
        $tenant = $this->tenant($request);
        $user = $this->user($request);

        return response()->json([
            'contract_version' => TenantMobileModuleRegistry::CONTRACT_VERSION,
            'generated_at' => now()->toIso8601String(),
            'user' => $this->userPayload($user),
            'workspace' => [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
                'role' => $this->tenantRole($user, $tenant),
            ],
            'experience_profile' => $experienceProfiles->forTenant((int) $tenant->id, $user, $tenant),
            'dashboard' => $dashboard->forRequest($request, $user),
            'modules' => $mobileModules->manifest((int) $tenant->id),
            'branches_summary' => [
                'active' => count((array) data_get($catalog->tenantStorePayload((int) $tenant->id, 'mobile'), 'sections.active', [])),
                'available' => count((array) data_get($catalog->tenantStorePayload((int) $tenant->id, 'mobile'), 'sections.available', [])),
            ],
            'permissions' => [
                'manage_billing' => $this->canManageBilling($user, $tenant),
                'request_modules' => in_array($this->tenantRole($user, $tenant), ['admin', 'manager', 'marketing_manager'], true),
            ],
        ])->setEtag(hash('sha256', (string) $user->id.'|'.$tenant->id.'|'.now()->format('Y-m-d-H-i')));
    }

    public function moduleScreen(Request $request, string $tenant, string $moduleKey, TenantMobileModuleRegistry $registry): JsonResponse
    {
        return response()->json($registry->screen((int) $this->tenant($request)->id, $moduleKey));
    }

    public function moduleAction(Request $request, string $tenant, string $moduleKey, string $actionKey, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $manifest = collect($registry->manifest((int) $tenantModel->id))
            ->firstWhere('module_key', strtolower(trim($moduleKey)));
        abort_unless(is_array($manifest) && in_array($actionKey, (array) ($manifest['actions'] ?? []), true), 404);

        abort_unless($moduleKey === 'field_service' && $actionKey === 'capture_photo', 422, 'This mobile action is not supported by the current contract.');
        $validated = $request->validate([
            'job_id' => ['required', 'integer'],
            'photo' => ['required', 'image', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $job = FieldServiceJob::query()
            ->forTenantId((int) $tenantModel->id)
            ->findOrFail((int) $validated['job_id']);
        $path = $request->file('photo')->store('field-service/'.$tenantModel->id.'/'.$job->id, 'public');

        $photo = FieldServiceJobPhoto::query()->create([
            'tenant_id' => (int) $tenantModel->id,
            'field_service_job_id' => (int) $job->id,
            'file_path' => Storage::disk('public')->url($path),
            'caption' => $validated['caption'] ?? null,
            'uploaded_by_user_id' => $this->user($request)->id,
            'captured_at' => $validated['captured_at'] ?? now(),
        ]);

        return response()->json([
            'ok' => true,
            'action' => 'capture_photo',
            'resource_id' => (int) $photo->id,
            'message' => 'Photo added to '.$job->title.'.',
        ], 201);
    }

    public function search(Request $request, GlobalSearchCoordinator $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json($search->search((string) ($validated['q'] ?? ''), [
            'tenant_id' => (int) $this->tenant($request)->id,
            'user' => $this->user($request),
            'request' => $request,
            'surface' => 'mobile',
            'limit' => (int) ($validated['limit'] ?? 10),
        ]));
    }

    public function branches(Request $request, TenantModuleCatalogService $catalog): JsonResponse
    {
        $tenant = $this->tenant($request);
        $payload = $catalog->tenantStorePayload((int) $tenant->id, 'mobile');

        return response()->json([
            ...$payload,
            'display_name' => 'Branches',
            'checkout' => [
                'enabled' => (bool) config('commercial.billing_readiness.checkout_active', false)
                    && (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false),
                'region' => 'US',
                'external_browser_required' => true,
            ],
        ]);
    }

    public function requestBranch(Request $request, string $tenant, string $moduleKey, TenantModuleCatalogService $catalog): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless(in_array($this->tenantRole($user, $tenantModel), ['admin', 'manager', 'marketing_manager'], true), 403);

        $result = $catalog->requestModuleAccessForTenant(
            tenantId: (int) $tenantModel->id,
            moduleKey: $moduleKey,
            actorId: (int) $user->id,
            source: 'everbranch_mobile_branches_request'
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function billingHandoff(
        Request $request,
        string $tenant,
        string $moduleKey,
        TenantModuleAccessResolver $accessResolver,
        StripeHostedBillingService $stripe
    ): JsonResponse {
        $validated = $request->validate([
            'platform' => ['required', 'in:ios,android'],
            'storefront_country' => ['required', 'string', 'max:3'],
        ]);
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($this->canManageBilling($user, $tenantModel), 403);

        $country = strtoupper($validated['storefront_country']);
        abort_unless(in_array($country, ['US', 'USA'], true), 403, 'Mobile checkout is currently available only in the US storefront.');

        $moduleKey = strtolower(trim($moduleKey));
        $definition = (array) config('module_catalog.modules.'.$moduleKey, []);
        abort_unless($definition !== []
            && (bool) data_get($definition, 'visibility.mobile_store', false)
            && strtolower((string) ($definition['billing_mode'] ?? '')) === 'add_on', 404);

        $purchaseKey = strtolower(trim((string) data_get($definition, 'mobile.purchase_key', $moduleKey)));
        $addonKey = collect((array) config('module_catalog.addons', []))
            ->search(static fn (mixed $addon, string $key): bool => $key === $purchaseKey
                || strtolower(trim((string) data_get($addon, 'purchase_key', ''))) === $purchaseKey);
        abort_unless(is_string($addonKey), 422, 'This Branch does not have a purchasable catalog mapping.');

        $resolved = $accessResolver->resolveForTenant((int) $tenantModel->id, [$moduleKey]);
        $planKey = (string) ($resolved['plan_key'] ?? config('module_catalog.defaults.plan', 'starter'));
        $result = $stripe->createCheckoutSession($tenantModel, $user, [
            'preferred_plan_key' => $planKey,
            'addons_interest' => [$addonKey],
            'source' => 'everbranch_mobile_branches',
            'captured_at' => now()->toIso8601String(),
            'access_request_id' => null,
        ]);

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'url' => $result['url'] ?? null,
            'session_id' => $result['session_id'] ?? null,
            'message' => $result['message'] ?? null,
            'open_in' => 'system_browser',
        ], ($result['ok'] ?? false) ? 200 : 409);
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return $user;
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /** @return array<string,mixed> */
    protected function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'initials' => $user->initials(),
        ];
    }

    protected function tenantRole(User $user, Tenant $tenant): string
    {
        $membership = $user->tenants()->whereKey((int) $tenant->id)->first();
        $role = strtolower(trim((string) ($membership?->pivot->role ?? $user->role ?? 'member')));

        return match ($role) {
            'owner', 'tenant_owner' => 'admin',
            default => $role !== '' ? $role : 'member',
        };
    }

    protected function canManageBilling(User $user, Tenant $tenant): bool
    {
        return $this->tenantRole($user, $tenant) === 'admin'
            || strtolower(trim((string) $user->role)) === 'platform_admin';
    }
}
