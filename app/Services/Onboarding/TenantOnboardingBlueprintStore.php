<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\User;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Support\Onboarding\AccountMode;
use App\Support\Onboarding\OnboardingBlueprint;
use App\Support\Onboarding\OnboardingRail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TenantOnboardingBlueprintStore
{
    /**
     * Draft persistence model (explicit):
     * - Drafts are stored as a single mutable record per (tenant_id, created_by_user_id) and overwritten on autosave.
     * - Finals are append-only snapshots (one per completion).
     */
    public function __construct(
        protected OnboardingBlueprintService $blueprintService,
        protected TenantAccountModeResolver $accountModeResolver,
        protected AuthenticatedTenantContextResolver $tenantContextResolver
    ) {
    }

    /**
     * Stage a draft blueprint (autosave seam).
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $origin
     */
    public function stageDraft(int $tenantId, array $input, ?int $actorUserId = null, array $origin = []): TenantOnboardingBlueprint
    {
        $this->assertBlueprintTablePresent();

        $tenant = Tenant::query()->with('accessProfile')->findOrFail($tenantId);

        $validated = $this->blueprintService->validateDraft([
            ...$input,
            'account_mode' => (string) ($input['account_mode'] ?? $this->accountModeResolver->resolveForTenant($tenant)->value),
        ]);

        $accountMode = (string) ($validated['account_mode'] ?? AccountMode::Production->value);
        $rail = (string) ($validated['rail'] ?? OnboardingRail::Direct->value);
        $version = (int) ($validated['version'] ?? OnboardingBlueprint::VERSION);

        $existing = null;
        if ($actorUserId !== null) {
            $existing = TenantOnboardingBlueprint::query()
                ->forTenantId($tenantId)
                ->where('status', 'draft')
                ->where('created_by_user_id', $actorUserId)
                ->latest('id')
                ->first();
        }

        if ($existing instanceof TenantOnboardingBlueprint) {
            $priorOrigin = is_array($existing->origin ?? null) ? (array) $existing->origin : [];
            $revision = is_numeric($priorOrigin['revision'] ?? null) ? (int) $priorOrigin['revision'] : 0;

            $existing->fill([
                'account_mode' => $accountMode,
                'rail' => $rail,
                'blueprint_version' => $version,
                'payload' => $validated,
                'origin' => array_replace($priorOrigin, $origin, [
                    'revision' => $revision + 1,
                    'last_saved_at' => now()->toIso8601String(),
                ]),
            ]);
            $existing->save();

            return $existing;
        }

        return TenantOnboardingBlueprint::query()->create([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $actorUserId,
            'status' => 'draft',
            'account_mode' => $accountMode,
            'rail' => $rail,
            'blueprint_version' => $version,
            'payload' => $validated,
            'origin' => array_replace($origin, [
                'revision' => 1,
                'last_saved_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Persist a final blueprint snapshot (wizard completion).
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $origin
     */
    public function finalize(int $tenantId, array $input, ?int $actorUserId = null, array $origin = []): TenantOnboardingBlueprint
    {
        $this->assertBlueprintTablePresent();

        $tenant = Tenant::query()->with('accessProfile')->findOrFail($tenantId);

        $validated = $this->blueprintService->validateFinal([
            ...$input,
            'account_mode' => (string) ($input['account_mode'] ?? $this->accountModeResolver->resolveForTenant($tenant)->value),
        ]);

        return TenantOnboardingBlueprint::query()->create([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $actorUserId,
            'status' => 'final',
            'account_mode' => (string) ($validated['account_mode'] ?? AccountMode::Production->value),
            'rail' => (string) ($validated['rail'] ?? OnboardingRail::Direct->value),
            'blueprint_version' => (int) ($validated['version'] ?? OnboardingBlueprint::VERSION),
            'payload' => $validated,
            'origin' => $origin,
        ]);
    }

    public function latestFinalForTenant(int $tenantId): ?TenantOnboardingBlueprint
    {
        if (! Schema::hasTable('tenant_onboarding_blueprints')) {
            return null;
        }

        return TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->where('status', 'final')
            ->latest('id')
            ->first();
    }

    public function latestDraftForTenant(int $tenantId, ?int $actorUserId = null): ?TenantOnboardingBlueprint
    {
        if (! Schema::hasTable('tenant_onboarding_blueprints')) {
            return null;
        }

        if ($actorUserId !== null) {
            $scoped = TenantOnboardingBlueprint::query()
                ->forTenantId($tenantId)
                ->where('status', 'draft')
                ->where('created_by_user_id', $actorUserId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            if ($scoped instanceof TenantOnboardingBlueprint) {
                return $scoped;
            }
        }

        return TenantOnboardingBlueprint::query()
            ->forTenantId($tenantId)
            ->where('status', 'draft')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Request-scoped entry point that resolves the tenant from current membership.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $origin
     */
    public function stageDraftForRequest(Request $request, User $user, array $input, array $origin = []): ?TenantOnboardingBlueprint
    {
        $tenant = $this->tenantContextResolver->resolveForRequest($request, $user);
        if (! $tenant instanceof Tenant) {
            return null;
        }

        return $this->stageDraft((int) $tenant->id, $input, (int) $user->id, $origin);
    }

    protected function assertBlueprintTablePresent(): void
    {
        if (! Schema::hasTable('tenant_onboarding_blueprints')) {
            throw new \RuntimeException('tenant_onboarding_blueprints table is missing; run migrations first.');
        }
    }
}
