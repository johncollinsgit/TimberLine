<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ClientProject;
use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobPhoto;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantDiscoveryProfile;
use App\Models\User;
use App\Services\Billing\StripeHostedBillingService;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\FieldService\FieldServiceWorkProfileService;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Mobile\MobileLandlordAccessService;
use App\Services\Mobile\TenantMobileMessagingService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Mobile\TenantMobileResourceService;
use App\Services\Mobile\TenantMobileSupportService;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
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
        TenantModuleCatalogService $catalog,
        FieldServiceWorkProfileService $workProfiles,
    ): JsonResponse {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $storePayload = $catalog->tenantStorePayload((int) $tenant->id, 'mobile');
        $role = $this->tenantRole($user, $tenant);
        $manifest = collect($mobileModules->manifest((int) $tenant->id))->values()->all();

        return response()->json([
            'contract_version' => TenantMobileModuleRegistry::CONTRACT_VERSION,
            'generated_at' => now()->toIso8601String(),
            'user' => $this->userPayload($user),
            'workspace' => [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
                'role' => $role,
            ],
            'branding' => $this->brandingPayload($tenant, $role),
            'experience_profile' => $experienceProfiles->forTenant((int) $tenant->id, $user, $tenant),
            'work_profile' => $workProfiles->forTenant($tenant),
            'dashboard' => $dashboard->forRequest($request, $user),
            'workspace_insights' => $this->workspaceInsights($tenant, count($manifest)),
            'branches' => $manifest,
            'modules' => $manifest,
            'branches_summary' => [
                'active' => count((array) data_get($storePayload, 'sections.active', [])),
                'available' => collect(['available', 'upgrade', 'request'])
                    ->sum(fn (string $section): int => count((array) data_get($storePayload, 'sections.'.$section, []))),
            ],
            'permissions' => [
                'manage_billing' => $this->canManageBilling($user, $tenant),
                'request_modules' => in_array($this->tenantRole($user, $tenant), ['admin', 'manager', 'marketing_manager'], true),
            ],
        ])->setEtag(hash('sha256', (string) $user->id.'|'.$tenant->id.'|'.now()->format('Y-m-d-H-i')));
    }

    public function moduleScreen(Request $request, string $tenant, string $moduleKey, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $range = $request->validate(['range' => ['nullable', 'in:1d,1w,1m,30d,ytd']])['range'] ?? null;

        return response()->json($registry->screen((int) $tenantModel->id, $moduleKey, $this->user($request), $range));
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
        $this->requireWorkBranch($registry, (int) $tenant->id);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160'], 'limit' => ['nullable', 'integer', 'min:10', 'max:50']]);

        return response()->json($resources->work($tenant, $this->user($request), (string) ($validated['q'] ?? ''), (int) ($validated['limit'] ?? 30)));
    }

    public function workDetail(Request $request, string $tenant, string $kind, int $resource, TenantMobileResourceService $resources, TenantMobileModuleRegistry $registry): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->requireWorkBranch($registry, (int) $tenantModel->id);

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

    public function updateBranding(Request $request, LandlordOperatorActionAuditService $audit): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $role = $this->tenantRole($user, $tenant);
        abort_unless($role === 'admin', 403, 'Only a workspace admin can change mobile branding.');

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'logo_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        $profile = TenantDiscoveryProfile::withoutGlobalScopes()->firstOrNew(['tenant_id' => (int) $tenant->id]);
        $before = [
            'primary_brand_name' => $profile->primary_brand_name,
            'primary_logo_url' => $profile->primary_logo_url,
        ];
        $profile->fill([
            'primary_brand_name' => trim($validated['display_name']),
            'primary_logo_url' => filled($validated['logo_url'] ?? null) ? trim((string) $validated['logo_url']) : null,
            'is_active' => true,
        ])->save();
        $after = [
            'primary_brand_name' => $profile->primary_brand_name,
            'primary_logo_url' => $profile->primary_logo_url,
        ];
        $audit->record((int) $tenant->id, (int) $user->id, 'tenant.mobile_branding.updated', targetType: 'tenant_discovery_profile', targetId: $profile->id, context: ['surface' => 'everbranch_mobile'], beforeState: $before, afterState: $after);

        return response()->json(['ok' => true, 'branding' => $this->brandingPayload($tenant->fresh(), $role)]);
    }

    public function supportTickets(Request $request, TenantMobileSupportService $support): JsonResponse
    {
        return response()->json($support->index((int) $this->tenant($request)->id));
    }

    public function supportTicket(Request $request, string $tenant, int $ticket, TenantMobileSupportService $support): JsonResponse
    {
        return response()->json($support->show((int) $this->tenant($request)->id, $ticket));
    }

    public function createSupportTicket(Request $request, TenantMobileSupportService $support): JsonResponse
    {
        $validated = $request->validate(['subject' => ['required', 'string', 'max:180'], 'body' => ['required', 'string', 'max:10000'], 'category' => ['required', 'in:help,bug,billing,feature,account'], 'priority' => ['required', 'in:low,normal,high,urgent']]);

        return response()->json($support->create($this->tenant($request), $this->user($request), $validated), 201);
    }

    public function replySupportTicket(Request $request, string $tenant, int $ticket, TenantMobileSupportService $support): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        return response()->json($support->reply((int) $this->tenant($request)->id, $ticket, $this->user($request), $validated['body'], 'tenant'));
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

    public function moduleAction(Request $request, string $tenant, string $moduleKey, string $actionKey, TenantMobileModuleRegistry $registry, WorkspaceAssetService $assets): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $manifest = collect($registry->manifest((int) $tenantModel->id))
            ->firstWhere('module_key', strtolower(trim($moduleKey)));
        abort_unless(is_array($manifest) && in_array($actionKey, (array) ($manifest['actions'] ?? []), true), 404);

        if ($moduleKey === 'documents' && $actionKey === 'upload_assets') {
            $validated = $request->validate([
                'files' => ['required', 'array', 'min:1', 'max:20'],
                'files.*' => ['required', 'file', 'max:25600'],
                'job_ids' => ['nullable', 'array'],
                'job_ids.*' => ['integer'],
                'caption' => ['nullable', 'string', 'max:255'],
                'visibility' => ['nullable', 'in:team,owner'],
            ]);
            $visibility = (string) ($validated['visibility'] ?? 'team');
            if ($visibility === 'owner') {
                abort_unless($this->canViewFinancials($this->user($request), $tenantModel), 403);
            }
            $created = [];
            foreach ($request->file('files', []) as $file) {
                $created[] = $assets->storeUpload($tenantModel, $this->user($request), $file, (array) ($validated['job_ids'] ?? []), $visibility, $validated['caption'] ?? null);
            }

            return response()->json([
                'ok' => true,
                'action' => 'upload_assets',
                'resource_ids' => collect($created)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'message' => count($created).' document'.(count($created) === 1 ? '' : 's').' added.',
            ], 201);
        }

        abort_unless($moduleKey === 'field_service' && in_array($actionKey, ['capture_photo', 'add_note'], true), 422, 'This mobile action is not supported by the current contract.');

        if ($actionKey === 'add_note') {
            $validated = $request->validate([
                'job_id' => ['required', 'integer'],
                'body' => ['required', 'string', 'max:5000'],
                'status_update' => ['nullable', 'string', 'in:open,scheduled,in_progress,blocked,done'],
                'noted_at' => ['nullable', 'date'],
            ]);

            $job = FieldServiceJob::query()
                ->forTenantId((int) $tenantModel->id)
                ->findOrFail((int) $validated['job_id']);

            $note = FieldServiceJobNote::query()->create([
                'tenant_id' => (int) $tenantModel->id,
                'field_service_job_id' => (int) $job->id,
                'created_by_user_id' => $this->user($request)->id,
                'body' => (string) $validated['body'],
                'status_update' => $validated['status_update'] ?? null,
                'noted_at' => $validated['noted_at'] ?? now(),
                'metadata' => ['source' => 'everbranch_mobile'],
            ]);

            if (filled($validated['status_update'] ?? null)) {
                $job->forceFill([
                    'status' => (string) $validated['status_update'],
                    'completed_at' => (string) $validated['status_update'] === 'done' ? ($job->completed_at ?? now()) : $job->completed_at,
                ])->save();
            }

            return response()->json([
                'ok' => true,
                'action' => 'add_note',
                'resource_id' => (int) $note->id,
                'message' => 'Update added to '.$job->title.'.',
            ], 201);
        }

        $validated = $request->validate([
            'job_id' => ['required', 'integer'],
            'photo' => ['required', 'image', 'max:10240'],
            'field_service_job_note_id' => ['nullable', 'integer'],
            'caption' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $job = FieldServiceJob::query()
            ->forTenantId((int) $tenantModel->id)
            ->findOrFail((int) $validated['job_id']);
        $noteId = null;
        if (is_numeric($validated['field_service_job_note_id'] ?? null)) {
            $noteId = FieldServiceJobNote::query()
                ->forTenantId((int) $tenantModel->id)
                ->where('field_service_job_id', (int) $job->id)
                ->whereKey((int) $validated['field_service_job_note_id'])
                ->value('id');
            abort_unless(is_numeric($noteId), 404);
            $noteId = (int) $noteId;
        }
        $path = $request->file('photo')->store('field-service/'.$tenantModel->id.'/'.$job->id, 'public');

        $photo = FieldServiceJobPhoto::query()->create([
            'tenant_id' => (int) $tenantModel->id,
            'field_service_job_id' => (int) $job->id,
            'field_service_job_note_id' => $noteId,
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
            $destination = match ((string) ($result['subtype'] ?? $result['type'] ?? '')) {
                'customer' => ['kind' => 'customer', 'id' => (int) data_get($result, 'meta.profile_id')],
                'order' => ['kind' => 'orders', 'id' => (int) data_get($result, 'meta.order_id')],
                'field_service_job' => ['kind' => 'field_service_job', 'id' => (int) data_get($result, 'meta.job_id')],
                'workspace_asset' => ['kind' => 'workspace_asset', 'id' => (int) data_get($result, 'meta.asset_id')],
                'field_service_estimate' => ['kind' => 'estimator_draft', 'id' => (int) data_get($result, 'meta.estimate_id')],
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

    protected function canViewFinancials(User $user, Tenant $tenant): bool
    {
        return in_array($this->tenantRole($user, $tenant), ['admin', 'owner', 'tenant_owner'], true)
            || strtolower(trim((string) $user->role)) === 'platform_admin';
    }

    /** @return array<string,mixed> */
    protected function brandingPayload(Tenant $tenant, string $role): array
    {
        $profile = TenantDiscoveryProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $name = trim((string) ($profile?->primary_brand_name ?: $tenant->name));
        $logo = trim((string) ($profile?->primary_logo_url ?? ''));
        if ($logo === '' && (int) $tenant->id === 1) {
            $logo = url('/brand/modern-forestry-logo-white.png');
        }

        return [
            'display_name' => $name !== '' ? $name : (string) $tenant->name,
            'logo_url' => $logo !== '' ? $logo : null,
            'logo_alt' => ($name !== '' ? $name : (string) $tenant->name).' logo',
            'can_manage' => $role === 'admin',
        ];
    }

    /** @return array<string,mixed> */
    protected function workspaceInsights(Tenant $tenant, int $activeBranches): array
    {
        $since = now()->subDays(30);
        $activity = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('updated_at', '>=', $since)->count()
            + FieldServiceJob::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('updated_at', '>=', $since)->count()
            + ClientProject::query()->where('tenant_id', $tenant->id)->where('updated_at', '>=', $since)->count();
        $activeUsers = $tenant->users()->whereHas('tokens', fn ($query) => $query->where('last_used_at', '>=', $since))->count();

        return [
            'users' => $tenant->users()->count(),
            'active_users_30d' => $activeUsers,
            'active_branches' => $activeBranches,
            'work_activity_30d' => $activity,
        ];
    }

    protected function requireBranch(TenantMobileModuleRegistry $registry, int $tenantId, string $moduleKey): void
    {
        abort_unless(collect($registry->manifest($tenantId))->contains('module_key', $moduleKey), 404);
    }

    protected function requireWorkBranch(TenantMobileModuleRegistry $registry, int $tenantId): void
    {
        $keys = collect($registry->manifest($tenantId))->pluck('module_key');
        abort_unless($keys->contains('field_service') || $keys->contains('work_core'), 404);
    }

    protected function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_unless($key !== '' && mb_strlen($key) <= 200, 422, 'An Idempotency-Key header is required.');

        return $key;
    }
}
