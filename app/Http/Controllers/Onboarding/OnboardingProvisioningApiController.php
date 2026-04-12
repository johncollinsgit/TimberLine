<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Services\Onboarding\OnboardingProvisioningHandoffPayloadService;
use App\Services\Onboarding\OnboardingProvisioningStatusService;
use App\Services\Onboarding\OnboardingProvisioningHandoffService;
use App\Services\Onboarding\OnboardingProvisioningOpenContextService;
use App\Services\Onboarding\OnboardingPostProvisioningSummaryService;
use App\Services\Onboarding\OnboardingFirstOpenAcknowledgmentService;
use App\Services\Onboarding\ProductionTenantProvisioningService;
use App\Support\Shopify\ShopifyEmbeddedContextQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OnboardingProvisioningApiController extends Controller
{
    public function __construct(
        protected ProductionTenantProvisioningService $provisioningService,
        protected OnboardingProvisioningStatusService $statusService,
        protected OnboardingProvisioningHandoffService $handoffService,
        protected OnboardingProvisioningHandoffPayloadService $handoffPayloadService,
        protected OnboardingProvisioningOpenContextService $openContextService,
        protected OnboardingPostProvisioningSummaryService $postProvisioningSummaryService,
        protected OnboardingFirstOpenAcknowledgmentService $firstOpenAcknowledgmentService
    ) {
    }

    public function provisionProductionTenant(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized before it can be consumed.'],
            ]);
        }

        // Safety: provision from the caller's finalized blueprint snapshot only.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $result = $this->provisioningService->provisionFromFinalBlueprint($blueprint, $request->user());

        return response()->json([
            'ok' => true,
            'result' => $result,
        ]);
    }

    public function provisioningStatus(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized to query provisioning status.'],
            ]);
        }

        // Safety: match provisioning gate - only allow the creator to inspect the blueprint provisioning status.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $status = $this->statusService->statusForFinalBlueprint($blueprint);

        return response()->json([
            'ok' => true,
            ...$status,
        ]);
    }

    public function provisioningHandoff(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized to query provisioning handoff.'],
            ]);
        }

        // Safety: match provisioning gate - only allow the creator to inspect the blueprint provisioning handoff.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $handoff = $this->handoffService->handoffForFinalBlueprint($blueprint);

        return response()->json([
            'ok' => true,
            ...$handoff,
        ]);
    }

    public function provisioningHandoffPayload(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
            'payload_anchor' => ['nullable', 'string', 'in:merchant_journey,onboarding,plans,integrations'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized to query provisioning handoff payload.'],
            ]);
        }

        // Safety: match provisioning gate - only allow the creator to inspect the blueprint provisioning handoff payload.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $payload = $this->handoffPayloadService->payloadForFinalBlueprint(
            finalBlueprint: $blueprint,
            payloadAnchor: isset($validated['payload_anchor']) ? (string) $validated['payload_anchor'] : null
        );

        return response()->json([
            'ok' => true,
            ...$payload,
        ]);
    }

    public function provisioningOpenContext(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized to query provisioning open context.'],
            ]);
        }

        // Safety: match provisioning gate - only allow the creator to inspect the blueprint provisioning open context.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $openContext = $this->openContextService->openContextForFinalBlueprint(
            finalBlueprint: $blueprint,
            currentTenantId: $tenantId,
            embeddedContextQuery: ShopifyEmbeddedContextQuery::fromRequest($request)
        );

        return response()->json([
            'ok' => true,
            ...$openContext,
        ]);
    }

    public function postProvisioningSummary(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
            'payload_anchor' => ['nullable', 'string', 'in:merchant_journey,onboarding,plans,integrations'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized to query post-provisioning summary.'],
            ]);
        }

        // Safety: match provisioning gate - only allow the creator to inspect post-provisioning orchestration state.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $summary = $this->postProvisioningSummaryService->summaryForFinalBlueprint(
            finalBlueprint: $blueprint,
            currentTenantId: $tenantId,
            embeddedContextQuery: ShopifyEmbeddedContextQuery::fromRequest($request),
            payloadAnchor: isset($validated['payload_anchor']) ? (string) $validated['payload_anchor'] : null
        );

        return response()->json([
            'ok' => true,
            ...$summary,
        ]);
    }

    public function acknowledgeFirstOpen(Request $request): JsonResponse
    {
        abort_unless((bool) config('features.internal_onboarding_provisioning', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $validated = $request->validate([
            'final_blueprint_id' => ['required', 'integer', 'min:1'],
            'payload_anchor' => ['nullable', 'string', 'in:merchant_journey,onboarding,plans,integrations'],
            'opened_path' => ['nullable', 'string', 'max:2048'],
            'destination_key' => ['nullable', 'string', 'max:120'],
        ]);

        $blueprint = TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $validated['final_blueprint_id'])
            ->first();

        if (! $blueprint instanceof TenantOnboardingBlueprint) {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Unknown blueprint id for this tenant.'],
            ]);
        }

        if ((string) ($blueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'final_blueprint_id' => ['Blueprint must be finalized to acknowledge first open.'],
            ]);
        }

        // Safety: match provisioning gate - only allow the creator to acknowledge first open.
        if ((int) ($blueprint->created_by_user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            abort(403);
        }

        $openedPath = isset($validated['opened_path']) ? trim((string) $validated['opened_path']) : null;
        if ($openedPath === '') {
            $openedPath = null;
        }

        // Optional: allow a lightweight destination key if a client doesn't have a path. Store it as a suffix
        // in opened_path (without inventing a second taxonomy).
        $destinationKey = isset($validated['destination_key']) ? trim((string) $validated['destination_key']) : null;
        if ($destinationKey !== null && $destinationKey !== '' && $openedPath === null) {
            $openedPath = 'destination:' . $destinationKey;
        }

        $result = $this->firstOpenAcknowledgmentService->acknowledgeFirstOpen(
            finalBlueprint: $blueprint,
            actorUserId: (int) ($request->user()?->id ?? 0),
            payloadAnchor: isset($validated['payload_anchor']) ? (string) $validated['payload_anchor'] : null,
            openedPath: $openedPath
        );

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }
}
