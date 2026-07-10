<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobPhoto;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\StripeHostedBillingService;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Mobile\MobileLandlordAccessService;
use App\Services\Mobile\TenantMobileMessagingService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Mobile\TenantMobileResourceService;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EverbranchMobileController extends Controller
{
    public function workspaces(Request $request, MobileLandlordAccessService $landlordAccess): JsonResponse
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
            'landlord_access' => $landlordAccess->allows($user),
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
            'branches' => $mobileModules->manifest((int) $tenant->id),
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

    public function customers(Request $request, TenantMobileResourceService $resources, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenant->id, 'customers');
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160'], 'cursor' => ['nullable', 'string', 'max:1000'], 'limit' => ['nullable', 'integer', 'min:10', 'max:50']]);

        return response()->json($resources->customers((int) $tenant->id, (string) ($validated['q'] ?? ''), $validated['cursor'] ?? null, (int) ($validated['limit'] ?? 25)));
    }

    public function customer(Request $request, string $tenant, int $customer, TenantMobileResourceService $resources, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenantModel->id, 'customers');

        return response()->json($resources->customer((int) $tenantModel->id, $customer));
    }

    public function work(Request $request, TenantMobileResourceService $resources, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenant->id, 'work_core');
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160'], 'limit' => ['nullable', 'integer', 'min:10', 'max:50']]);

        return response()->json($resources->work($tenant, $this->user($request), (string) ($validated['q'] ?? ''), (int) ($validated['limit'] ?? 30)));
    }

    public function workDetail(Request $request, string $tenant, string $kind, int $resource, TenantMobileResourceService $resources, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenantModel->id, 'work_core');

        return response()->json($resources->workDetail($tenantModel, $this->user($request), $kind, $resource));
    }

    public function conversations(Request $request, TenantMobileMessagingService $messaging, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenant->id, 'messaging');
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160'], 'filter' => ['nullable', 'in:open,unread,all,closed,archived'], 'channel' => ['nullable', 'in:all,text,email,app'], 'limit' => ['nullable', 'integer', 'min:10', 'max:50']]);

        return response()->json($messaging->index((int) $tenant->id, ['search' => $validated['q'] ?? '', ...$validated]));
    }

    public function conversation(Request $request, string $tenant, int $conversation, TenantMobileMessagingService $messaging, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenantModel->id, 'messaging');

        return response()->json($messaging->show((int) $tenantModel->id, $conversation));
    }

    public function messageCustomers(Request $request, TenantMobileMessagingService $messaging, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenant->id, 'messaging');
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160']]);

        return response()->json(['customers' => $messaging->searchCustomers((int) $tenant->id, (string) ($validated['q'] ?? ''))]);
    }

    public function composeMessage(Request $request, TenantMobileMessagingService $messaging, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenant->id, 'messaging');
        $validated = $request->validate(['customer_id' => ['required', 'integer'], 'channel' => ['required', 'in:text,email,app'], 'body' => ['required', 'string', 'max:10000'], 'subject' => ['nullable', 'string', 'max:255']]);

        return response()->json($messaging->compose((int) $tenant->id, $this->user($request), (int) $validated['customer_id'], $validated['channel'], $validated['body'], $validated['subject'] ?? null, $this->idempotencyKey($request)), 201);
    }

    public function replyMessage(Request $request, string $tenant, int $conversation, TenantMobileMessagingService $messaging, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenantModel->id, 'messaging');
        $validated = $request->validate(['body' => ['required', 'string', 'max:10000'], 'subject' => ['nullable', 'string', 'max:255']]);

        return response()->json($messaging->reply((int) $tenantModel->id, $this->user($request), $conversation, $validated['body'], $validated['subject'] ?? null, $this->idempotencyKey($request)));
    }

    public function conversationAction(Request $request, string $tenant, int $conversation, TenantMobileMessagingService $messaging, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->requireBranch($registry, (int) $tenantModel->id, 'messaging');
        $validated = $request->validate(['action' => ['required', 'in:mark_read,mark_unread,assign_to_me,unassign,close,reopen,archive']]);

        return response()->json($messaging->action((int) $tenantModel->id, $this->user($request), $conversation, $validated['action']));
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['preferences' => (array) data_get($this->user($request)->ui_preferences, 'mobile', [])]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate(['appearance' => ['sometimes', 'in:system,light,dark'], 'notifications' => ['sometimes', 'boolean'], 'biometric_reentry' => ['sometimes', 'boolean']]);
        $user = $this->user($request);
        $preferences = (array) ($user->ui_preferences ?? []);
        $preferences['mobile'] = [...(array) ($preferences['mobile'] ?? []), ...$validated];
        $user->forceFill(['ui_preferences' => $preferences])->save();

        return response()->json(['ok' => true, 'preferences' => $preferences['mobile']]);
    }

    public function registerPushDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:ios,android'],
            'device_token' => ['required', 'string', 'max:4096'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:160'],
        ]);
        $user = $this->user($request);
        $hash = hash('sha256', $validated['device_token']);
        $device = EverbranchMobilePushDevice::query()->updateOrCreate(
            ['device_token_hash' => $hash],
            [
                'user_id' => (int) $user->id,
                'platform' => $validated['platform'],
                'device_token' => $validated['device_token'],
                'app_version' => $validated['app_version'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'notifications_enabled' => true,
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['ok' => true, 'device_id' => (int) $device->id], 201);
    }

    public function unregisterPushDevice(Request $request): JsonResponse
    {
        $validated = $request->validate(['device_token' => ['required', 'string', 'max:4096']]);
        EverbranchMobilePushDevice::query()
            ->where('user_id', $this->user($request)->id)
            ->where('device_token_hash', hash('sha256', $validated['device_token']))
            ->delete();

        return response()->json(['ok' => true]);
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

    public function search(Request $request, GlobalSearchCoordinator $search, TenantMobileResourceService $resources): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $tenant = $this->tenant($request);
        $query = (string) ($validated['q'] ?? '');
        $payload = $search->search($query, [
            'tenant_id' => (int) $tenant->id,
            'user' => $this->user($request),
            'request' => $request,
            'surface' => 'mobile',
            'limit' => (int) ($validated['limit'] ?? 10),
        ]);
        $results = collect((array) ($payload['results'] ?? []))->map(function (array $result): array {
            $destination = match ((string) ($result['type'] ?? '')) {
                'customer' => ['kind' => 'customer', 'id' => (int) data_get($result, 'meta.profile_id')],
                'order' => ['kind' => 'orders', 'id' => (int) data_get($result, 'meta.order_id')],
                default => null,
            };

            return $destination ? [...$result, 'mobile_destination' => $destination] : $result;
        });
        $work = $resources->work($tenant, $this->user($request), $query, 8);
        if (($work['kind'] ?? null) !== 'orders') {
            $results = $results->concat(collect((array) ($work['items'] ?? []))->map(fn (array $item): array => [
                'type' => rtrim((string) $work['kind'], 's'),
                'title' => $item['title'],
                'subtitle' => $item['subtitle'] ?? '',
                'badge' => $item['status'] ?? $work['label'],
                'mobile_destination' => ['kind' => $work['kind'], 'id' => $item['id']],
            ]));
        }
        $payload['results'] = $results->take((int) ($validated['limit'] ?? 20))->values()->all();
        $payload['total'] = count($payload['results']);

        return response()->json($payload);
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

    protected function requireBranch(TenantMobileModuleRegistry $registry, int $tenantId, string $moduleKey): void
    {
        abort_unless(collect($registry->manifest($tenantId))->contains('module_key', $moduleKey), 404);
    }

    protected function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_unless($key !== '' && mb_strlen($key) <= 200, 422, 'An Idempotency-Key header is required.');

        return $key;
    }
}
