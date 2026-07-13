<?php

namespace App\Http\Controllers;

use App\Models\CustomerMergeOperation;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CustomerMergeCandidateService;
use App\Services\Marketing\CustomerMergeCoordinator;
use App\Services\Marketing\CustomerMergeException;
use App\Services\Marketing\CustomerMergeReadinessService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ShopifyEmbeddedCustomerMergeController extends Controller
{
    public function candidates(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, CustomerMergeCandidateService $candidates): JsonResponse
    {
        [$tenantId, $storeKey] = $this->context($request, $contexts, $tenants);
        $query = $request->validate(['q' => ['required', 'string', 'max:190']])['q'];

        return response()->json(['ok' => true, 'data' => $candidates->search($tenantId, $query, $storeKey)]);
    }

    public function preview(
        Request $request,
        ShopifyEmbeddedAppContext $contexts,
        TenantResolver $tenants,
        CustomerMergeCandidateService $candidates,
        CustomerMergeCoordinator $coordinator,
        CustomerMergeReadinessService $readiness
    ): JsonResponse {
        [$tenantId, $storeKey, $context] = $this->context($request, $contexts, $tenants);
        $data = $request->validate([
            'profile_ids' => ['required', 'array', 'min:2', 'max:20'],
            'profile_ids.*' => ['required', 'integer', 'distinct'],
            'survivor_profile_id' => ['required', 'integer'],
            'field_sources' => ['sometimes', 'array'],
            'field_sources.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'array', 'max:250'],
            'tags.*' => ['string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
            'reward_resolution' => ['sometimes', 'array'],
            'reward_resolution.ambiguous_balances' => ['sometimes', 'array'],
            'reward_resolution.ambiguous_balances.*' => ['in:include_as_opening,discard_duplicate'],
        ]);
        $selected = $candidates->selected($tenantId, $data['profile_ids'], $storeKey);
        $gids = collect($selected)->mapWithKeys(fn (array $row): array => [(int) $row['id'] => $row['shopify_customer_gid']]);
        $fieldSources = (array) ($data['field_sources'] ?? []);
        $overrides = $this->shopifyOverrides($fieldSources, $gids->all(), $data, $selected);
        $store = $this->store($storeKey, $tenantId);
        $actor = $this->resolveActor($request->user(), $context, $tenantId);

        try {
            $readiness->assertReady($store);
            $result = $coordinator->prepare(
                $tenantId,
                $storeKey,
                $store,
                $data['profile_ids'],
                (int) $data['survivor_profile_id'],
                $fieldSources,
                $overrides,
                trim((string) ($data['idempotency_key'] ?? '')) ?: (string) Str::uuid(),
                [
                    'initiated_by' => $actor?->id,
                    'shopify_admin_user_id' => data_get($context, 'shopify_admin_user_id'),
                ],
                (array) ($data['reward_resolution'] ?? [])
            );
        } catch (CustomerMergeException $exception) {
            return $this->mergeError($exception);
        } catch (Throwable $exception) {
            Log::error('Customer merge preview failed unexpectedly.', [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'status' => 'customer_merge_preview_failed',
                'message' => 'Everbranch could not prepare this merge. No customer records were changed; review the operation logs and try again.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'operation' => $this->operationPayload($result['operation']),
                'selected' => $selected,
                'blockers' => $result['blockers'],
                'everbranch_only' => $result['everbranch_only'],
                'can_execute' => $this->canApprove($actor, $tenantId),
            ],
        ]);
    }

    public function approve(Request $request, CustomerMergeOperation $operation, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, CustomerMergeCoordinator $coordinator, CustomerMergeReadinessService $readiness): JsonResponse
    {
        [$tenantId, $storeKey, $context] = $this->context($request, $contexts, $tenants);
        abort_unless((int) $operation->tenant_id === $tenantId && (string) $operation->store_key === $storeKey, 404);
        $confirmation = trim((string) $request->validate(['confirmation' => ['required', 'string', 'max:190']])['confirmation']);
        $survivor = MarketingProfile::query()->where('tenant_id', $tenantId)->findOrFail($operation->survivor_profile_id);
        $validConfirmations = array_filter([strtolower(trim((string) $survivor->email)), strtolower(trim($survivor->first_name.' '.$survivor->last_name))]);
        if (! in_array(strtolower($confirmation), $validConfirmations, true)) {
            return response()->json(['ok' => false, 'message' => 'Enter the surviving email or customer name exactly.'], 422);
        }
        $actor = $this->resolveActor($request->user(), $context, $tenantId);
        if (! $this->canApprove($actor, $tenantId)) {
            return response()->json([
                'ok' => false,
                'status' => 'admin_approval_required',
                'message' => 'Sign in to Everbranch with an active tenant owner/admin account matching your Shopify admin email before approving this merge.',
            ], 403);
        }

        try {
            $store = $this->store($storeKey, $tenantId);
            $readiness->assertReady($store);
            $operation = $coordinator->execute($operation, $store, (int) $actor->id);
        } catch (CustomerMergeException $exception) {
            return $this->mergeError($exception);
        } catch (Throwable $exception) {
            Log::error('Customer merge approval failed unexpectedly.', [
                'operation_id' => (int) $operation->id,
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'status' => 'customer_merge_execution_failed',
                'message' => 'Everbranch could not safely continue this merge. Check the operation status before retrying.',
            ], 500);
        }

        return response()->json(['ok' => true, 'data' => $this->operationPayload($operation)]);
    }

    public function status(Request $request, CustomerMergeOperation $operation, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, CustomerMergeCoordinator $coordinator): JsonResponse
    {
        [$tenantId, $storeKey] = $this->context($request, $contexts, $tenants);
        abort_unless((int) $operation->tenant_id === $tenantId && (string) $operation->store_key === $storeKey, 404);
        if ($operation->status === 'processing' && $operation->shopify_job_id) {
            $operation = $coordinator->advance($operation, $this->store($storeKey, $tenantId));
        }

        return response()->json(['ok' => true, 'data' => $this->operationPayload($operation->fresh('members'))]);
    }

    private function context(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants): array
    {
        abort_unless((bool) config('customer_merge.enabled'), 404);
        $context = $contexts->resolveAuthenticatedApiContext($request);
        abort_unless((bool) ($context['ok'] ?? false), 401);
        $tenantId = $tenants->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        abort_unless($tenantId !== null, 404);
        $tenant = Tenant::query()->find($tenantId);
        $allowedSlugs = (array) config('customer_merge.tenant_slugs', []);
        abort_unless($allowedSlugs === [] || in_array((string) $tenant?->slug, $allowedSlugs, true), 404);

        return [(int) $tenantId, (string) data_get($context, 'store.key'), $context];
    }

    private function store(string $storeKey, int $tenantId): array
    {
        $store = ShopifyStores::find($storeKey);
        if (! $store || (int) ($store['tenant_id'] ?? 0) !== $tenantId) {
            throw new CustomerMergeException('The Shopify store could not be resolved for this tenant.', 'tenant_scope_mismatch');
        }

        return $store;
    }

    private function canApprove(?User $user, int $tenantId): bool
    {
        return $user instanceof User
            && (bool) $user->is_active
            && ($user->isAdmin() || $user->role === 'platform_admin')
            && ($user->role === 'platform_admin' || in_array($tenantId, $user->accessibleTenantIds(), true));
    }

    private function resolveActor(?User $sessionUser, array $context, int $tenantId): ?User
    {
        if ($this->canApprove($sessionUser, $tenantId)) {
            return $sessionUser;
        }

        $email = strtolower(trim((string) ($context['shopify_admin_email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $candidate = User::query()
            ->where('is_active', true)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        return $this->canApprove($candidate, $tenantId) ? $candidate : null;
    }

    private function shopifyOverrides(array $sources, array $gids, array $data, array $selectedProfiles): array
    {
        $map = [
            'first_name' => 'customerIdOfFirstNameToKeep', 'last_name' => 'customerIdOfLastNameToKeep',
            'email' => 'customerIdOfEmailToKeep', 'phone' => 'customerIdOfPhoneNumberToKeep',
            'address_line_1' => 'customerIdOfDefaultAddressToKeep',
        ];
        $overrides = [];
        foreach ($map as $field => $shopifyField) {
            $sourceId = (int) ($sources[$field] ?? 0);
            if ($sourceId && isset($gids[$sourceId]) && $gids[$sourceId]) {
                $overrides[$shopifyField] = $gids[$sourceId];
            }
        }
        $profilesById = collect($selectedProfiles)->keyBy('id');
        if (! array_key_exists('note', $data) && isset($sources['notes'])) {
            $overrides['note'] = (string) data_get($profilesById->get((int) $sources['notes']), 'notes', '');
        }
        if (! array_key_exists('tags', $data) && isset($sources['tags'])) {
            $overrides['tags'] = array_values((array) data_get($profilesById->get((int) $sources['tags']), 'tags', []));
        }
        if (array_key_exists('note', $data)) {
            $overrides['note'] = $data['note'];
        }
        if (array_key_exists('tags', $data)) {
            $overrides['tags'] = array_values(array_unique(array_map('trim', $data['tags'])));
        }

        return $overrides;
    }

    private function operationPayload(CustomerMergeOperation $operation): array
    {
        return [
            'id' => (int) $operation->id, 'status' => $operation->status,
            'survivor_profile_id' => (int) $operation->survivor_profile_id,
            'resulting_shopify_customer_id' => $operation->shopify_kept_customer_gid,
            'shopify_job_id' => $operation->shopify_job_id,
            'preview' => $operation->shopify_preview, 'reward_resolution' => $operation->reward_resolution,
            'errors' => $operation->errors, 'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }

    private function mergeError(CustomerMergeException $exception): JsonResponse
    {
        return response()->json(['ok' => false, 'status' => $exception->publicCode(), 'message' => $exception->getMessage()], 422);
    }
}
