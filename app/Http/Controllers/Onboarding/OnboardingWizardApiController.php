<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingWizardContractService;
use App\Services\Onboarding\Rails\OnboardingRailAdapterRegistry;
use App\Services\Onboarding\TenantAccountModeResolver;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Support\Onboarding\OnboardingRail;
use App\Support\Onboarding\OnboardingWizardContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class OnboardingWizardApiController extends Controller
{
    public function __construct(
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantAccountModeResolver $accountModeResolver,
        protected TenantOnboardingBlueprintStore $blueprintStore,
        protected OnboardingWizardContractService $contractService,
        protected OnboardingRailAdapterRegistry $adapterRegistry
    ) {
    }

    public function contract(Request $request): JsonResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $experience = $this->experienceProfileService->forTenant($tenantId, $request->user(), $tenant);
        $availability = is_array($experience['data_availability'] ?? null) ? (array) $experience['data_availability'] : [];

        $rail = OnboardingRail::tryFrom((string) ($final->rail ?? ''))
            ?? $this->railForRequest($request, (string) ($experience['operating_mode'] ?? ''));
        $accountMode = $this->accountModeResolver->resolveForTenant($tenant);

        $context = new OnboardingWizardContext(
            rail: $rail,
            accountMode: $accountMode,
            tenantId: $tenantId,
            hasShopifyContext: (bool) ($availability['shopify'] ?? false)
        );

        $draft = $this->blueprintStore->latestDraftForTenant($tenantId, $request->user()?->id);
        $draftPayload = is_array($draft?->payload ?? null) ? (array) $draft->payload : [];

        $contract = $this->contractService->contractForContext($context, $draftPayload);

        return response()->json([
            'ok' => true,
            'tenant_id' => $tenantId,
            'draft' => $draft instanceof \App\Models\TenantOnboardingBlueprint
                ? [
                    'id' => (int) $draft->id,
                    'status' => (string) ($draft->status ?? 'draft'),
                    'saved_at' => $draft->created_at?->toIso8601String(),
                    'payload' => $draftPayload,
                ]
                : null,
            'contract' => $contract,
        ]);
    }

    public function autosaveDraft(Request $request): JsonResponse
    {
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null) {
            abort(403);
        }

        $experience = $this->experienceProfileService->forTenant($tenantId, $request->user());
        $derivedRail = $this->railForOperatingMode((string) ($experience['operating_mode'] ?? ''));
        $actorId = $request->user()?->id;

        $input = Arr::only($request->all(), [
            'rail',
            'template_key',
            'desired_outcome_first',
            'selected_modules',
            'data_source',
            'setup_preferences',
            'mobile_intent',
            'demo_origin',
        ]);

        if (! isset($input['rail']) || trim((string) $input['rail']) === '') {
            $input['rail'] = $derivedRail->value;
        }

        try {
            $saved = $this->blueprintStore->stageDraft(
                tenantId: $tenantId,
                input: $input,
                actorUserId: $actorId ? (int) $actorId : null,
                origin: [
                    'source' => 'autosave',
                    'path' => (string) $request->path(),
                ]
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $payload = is_array($saved->payload ?? null) ? (array) $saved->payload : [];

        return response()->json([
            'ok' => true,
            'tenant_id' => $tenantId,
            'draft' => [
                'id' => (int) $saved->id,
                'status' => (string) ($saved->status ?? 'draft'),
                'saved_at' => $saved->created_at?->toIso8601String(),
                'payload' => $payload,
            ],
        ]);
    }

    public function finalizeBlueprint(Request $request): JsonResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = is_numeric($request->attributes->get('current_tenant_id'))
            ? (int) $request->attributes->get('current_tenant_id')
            : null;

        if ($tenantId === null || ! $tenant instanceof Tenant) {
            abort(403);
        }

        $actorId = $request->user()?->id;
        if (! is_numeric($actorId)) {
            abort(401);
        }

        $final = $this->blueprintStore->finalizeLatestDraft(
            tenantId: $tenantId,
            actorUserId: (int) $actorId,
            origin: [
                'source' => 'api_finalize',
                'path' => (string) $request->path(),
            ]
        );

        $payload = is_array($final->payload ?? null) ? (array) $final->payload : [];

        $experience = $this->experienceProfileService->forTenant($tenantId, $request->user(), $tenant);
        $availability = is_array($experience['data_availability'] ?? null) ? (array) $experience['data_availability'] : [];

        $rail = $this->railForRequest($request, (string) ($experience['operating_mode'] ?? ''));
        $accountMode = $this->accountModeResolver->resolveForTenant($tenant);

        $context = new OnboardingWizardContext(
            rail: $rail,
            accountMode: $accountMode,
            tenantId: $tenantId,
            hasShopifyContext: (bool) ($availability['shopify'] ?? false)
        );

        $contract = $this->contractService->contractForContext($context, $payload);

        return response()->json([
            'ok' => true,
            'tenant_id' => $tenantId,
            'final' => [
                'id' => (int) $final->id,
                'tenant_id' => $tenantId,
                'status' => (string) ($final->status ?? 'final'),
                'finalized_at' => $final->created_at?->toIso8601String(),
                'account_mode' => (string) ($final->account_mode ?? ''),
                'rail' => (string) ($final->rail ?? ''),
                'blueprint_version' => (int) ($final->blueprint_version ?? 0),
                'payload' => $payload,
                'origin' => is_array($final->origin ?? null) ? (array) $final->origin : [],
            ],
            'meta' => [
                'blueprint_only' => true,
                'tenant_creation_policy' => (string) ($payload['tenant_creation_policy'] ?? ''),
            ],
            'contract' => $contract,
        ]);
    }

    protected function railForRequest(Request $request, string $fallbackOperatingMode): OnboardingRail
    {
        $raw = strtolower(trim((string) $request->query('rail', '')));
        if ($raw !== '') {
            $rail = OnboardingRail::tryFrom($raw);
            if (! $rail instanceof OnboardingRail) {
                throw ValidationException::withMessages([
                    'rail' => ['Invalid rail value.'],
                ]);
            }

            return $rail;
        }

        return $this->railForOperatingMode($fallbackOperatingMode);
    }

    protected function railForOperatingMode(string $operatingMode): OnboardingRail
    {
        $normalized = strtolower(trim($operatingMode));

        return $normalized === 'shopify' ? OnboardingRail::Shopify : OnboardingRail::Direct;
    }
}
