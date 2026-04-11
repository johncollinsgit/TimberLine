<?php

namespace App\Services\Onboarding;

use App\Services\Tenancy\TenantModuleCatalogService;
use App\Support\Onboarding\AccountMode;
use App\Support\Onboarding\MobileIntent;
use App\Support\Onboarding\MobileJob;
use App\Support\Onboarding\MobileRole;
use App\Support\Onboarding\OnboardingBlueprint;
use App\Support\Onboarding\OnboardingRail;
use Illuminate\Validation\ValidationException;

class OnboardingBlueprintService
{
    public function __construct(
        protected TenantModuleCatalogService $moduleCatalogService
    ) {
    }

    /**
     * Validate a draft payload (safe to autosave mid-wizard).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function validateDraft(array $input): array
    {
        $rail = $this->railFromInput($input);
        $accountMode = $this->accountModeFromInput($input) ?? AccountMode::Production;

        $validated = validator($input, [
            'rail' => ['required', 'string', 'in:shopify,direct'],
            'account_mode' => ['nullable', 'string', 'in:preview,demo,production'],
            'template_key' => ['nullable', 'string', 'max:120'],
            'desired_outcome_first' => ['nullable', 'string', 'max:180'],
            'selected_modules' => ['sometimes', 'array'],
            'selected_modules.*' => ['string', 'max:120'],
            'data_source' => ['nullable', 'string', 'max:80'],
            'setup_preferences' => ['sometimes', 'array'],
            'demo_origin' => ['sometimes', 'array'],
            'mobile_intent' => ['sometimes', 'array'],
            'mobile_intent.needs_mobile_access' => ['sometimes', 'boolean'],
            'mobile_intent.mobile_roles_needed' => ['sometimes', 'array'],
            'mobile_intent.mobile_roles_needed.*' => ['string'],
            'mobile_intent.mobile_jobs_requested' => ['sometimes', 'array'],
            'mobile_intent.mobile_jobs_requested.*' => ['string'],
            'mobile_intent.mobile_priority' => ['nullable', 'string', 'max:80'],
        ])->validate();

        return $this->normalize(
            input: $validated,
            rail: $rail,
            accountMode: $accountMode,
            strict: false
        );
    }

    /**
     * Validate a final blueprint payload (wizard completion).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function validateFinal(array $input): array
    {
        $rail = $this->railFromInput($input);
        $accountMode = $this->accountModeFromInput($input) ?? AccountMode::Production;

        $validated = validator($input, [
            'rail' => ['required', 'string', 'in:shopify,direct'],
            'account_mode' => ['nullable', 'string', 'in:preview,demo,production'],
            'template_key' => ['required', 'string', 'max:120'],
            'desired_outcome_first' => ['required', 'string', 'max:180'],
            'selected_modules' => ['required', 'array'],
            'selected_modules.*' => ['string', 'max:120'],
            'data_source' => ['required', 'string', 'in:shopify,csv,manual,connector'],
            'setup_preferences' => ['sometimes', 'array'],
            'demo_origin' => ['sometimes', 'array'],
            'mobile_intent' => ['required', 'array'],
            'mobile_intent.needs_mobile_access' => ['required', 'boolean'],
            'mobile_intent.mobile_roles_needed' => ['sometimes', 'array'],
            'mobile_intent.mobile_roles_needed.*' => ['string'],
            'mobile_intent.mobile_jobs_requested' => ['sometimes', 'array'],
            'mobile_intent.mobile_jobs_requested.*' => ['string'],
            'mobile_intent.mobile_priority' => ['nullable', 'string', 'max:80'],
        ])->validate();

        return $this->normalize(
            input: $validated,
            rail: $rail,
            accountMode: $accountMode,
            strict: true
        );
    }

    /**
     * @param  array<string,mixed>  $validatedBlueprint
     */
    public function toBlueprint(array $validatedBlueprint): OnboardingBlueprint
    {
        $accountMode = $this->accountModeFromInput($validatedBlueprint) ?? AccountMode::Production;
        $rail = $this->railFromInput($validatedBlueprint);

        $mobileIntent = $this->mobileIntentFromValidated($validatedBlueprint);

        return new OnboardingBlueprint(
            accountMode: $accountMode,
            rail: $rail,
            templateKey: $this->nullableString($validatedBlueprint['template_key'] ?? null),
            desiredOutcomeFirst: $this->nullableString($validatedBlueprint['desired_outcome_first'] ?? null),
            selectedModuleKeys: array_values(array_map(
                'strval',
                (array) ($validatedBlueprint['selected_modules'] ?? [])
            )),
            dataSource: $this->nullableString($validatedBlueprint['data_source'] ?? null),
            setupPreferences: is_array($validatedBlueprint['setup_preferences'] ?? null)
                ? (array) $validatedBlueprint['setup_preferences']
                : [],
            mobileIntent: $mobileIntent,
            demoOrigin: is_array($validatedBlueprint['demo_origin'] ?? null)
                ? (array) $validatedBlueprint['demo_origin']
                : [],
            tenantCreationPolicy: (string) ($validatedBlueprint['tenant_creation_policy'] ?? $this->tenantCreationPolicy($accountMode))
        );
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function normalize(array $input, OnboardingRail $rail, AccountMode $accountMode, bool $strict): array
    {
        $templateKey = $this->nullableString($input['template_key'] ?? null);
        $this->assertTemplateKeyAllowed($templateKey);

        $normalizedModules = $this->canonicalModuleKeys((array) ($input['selected_modules'] ?? []));

        $unknown = array_values(array_diff($normalizedModules, $this->knownModuleKeys()));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'selected_modules' => ['Unknown module key(s): '.implode(', ', $unknown)],
            ]);
        }

        $dataSource = $this->nullableString($input['data_source'] ?? null);
        if ($dataSource === null) {
            $dataSource = $rail === OnboardingRail::Shopify ? 'shopify' : 'csv';
        }

        $mobile = is_array($input['mobile_intent'] ?? null) ? (array) $input['mobile_intent'] : [];
        $needsMobileAccess = $mobile['needs_mobile_access'] ?? null;
        if ($strict && ! is_bool($needsMobileAccess)) {
            throw ValidationException::withMessages([
                'mobile_intent.needs_mobile_access' => ['Mobile intent must include needs_mobile_access.'],
            ]);
        }

        $mobileRoles = $this->stringList($mobile['mobile_roles_needed'] ?? []);
        $invalidRoles = array_values(array_diff($mobileRoles, $this->validMobileRoleValues()));
        if ($invalidRoles !== []) {
            throw ValidationException::withMessages([
                'mobile_intent.mobile_roles_needed' => ['Unknown mobile role(s): '.implode(', ', $invalidRoles)],
            ]);
        }

        $mobileJobs = $this->stringList($mobile['mobile_jobs_requested'] ?? []);
        $invalidJobs = array_values(array_diff($mobileJobs, $this->validMobileJobValues()));
        if ($invalidJobs !== []) {
            throw ValidationException::withMessages([
                'mobile_intent.mobile_jobs_requested' => ['Unknown mobile job(s): '.implode(', ', $invalidJobs)],
            ]);
        }

        return [
            'version' => OnboardingBlueprint::VERSION,
            'account_mode' => $accountMode->value,
            'rail' => $rail->value,
            'template_key' => $templateKey,
            'desired_outcome_first' => $this->nullableString($input['desired_outcome_first'] ?? null),
            'selected_modules' => $normalizedModules,
            'data_source' => $dataSource,
            'setup_preferences' => is_array($input['setup_preferences'] ?? null) ? (array) $input['setup_preferences'] : [],
            'mobile_intent' => [
                'needs_mobile_access' => is_bool($needsMobileAccess) ? $needsMobileAccess : false,
                'mobile_roles_needed' => $mobileRoles,
                'mobile_jobs_requested' => $mobileJobs,
                'mobile_priority' => $this->nullableString($mobile['mobile_priority'] ?? null),
            ],
            'demo_origin' => is_array($input['demo_origin'] ?? null) ? (array) $input['demo_origin'] : [],
            'tenant_creation_policy' => $this->tenantCreationPolicy($accountMode),
        ];
    }

    protected function tenantCreationPolicy(AccountMode $accountMode): string
    {
        return $accountMode === AccountMode::Demo
            ? 'create_fresh_production_tenant'
            : 'use_existing_tenant';
    }

    /**
     * @param  array<int,mixed>  $modules
     * @return array<int,string>
     */
    protected function canonicalModuleKeys(array $modules): array
    {
        $normalized = [];

        foreach ($modules as $moduleKey) {
            $canonical = $this->moduleCatalogService->canonicalModuleKey((string) $moduleKey);
            if ($canonical === '') {
                continue;
            }

            $normalized[] = $canonical;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int,string>
     */
    protected function knownModuleKeys(): array
    {
        return array_keys((array) config('module_catalog.modules', []));
    }

    protected function assertTemplateKeyAllowed(?string $templateKey): void
    {
        if ($templateKey === null) {
            return;
        }

        $templates = (array) config('commercial.templates', []);
        if ($templates === []) {
            return;
        }

        $allowed = [];
        foreach ($templates as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (($definition['active'] ?? true) !== false) {
                $allowed[] = strtolower(trim((string) $key));
            }
        }

        $allowed = array_values(array_filter($allowed, static fn (string $key): bool => $key !== ''));
        if ($allowed === []) {
            return;
        }

        if (! in_array(strtolower(trim($templateKey)), $allowed, true)) {
            throw ValidationException::withMessages([
                'template_key' => ['Unknown template key: '.$templateKey],
            ]);
        }
    }

    protected function railFromInput(array $input): OnboardingRail
    {
        $raw = strtolower(trim((string) ($input['rail'] ?? '')));

        return match ($raw) {
            'shopify' => OnboardingRail::Shopify,
            default => OnboardingRail::Direct,
        };
    }

    protected function accountModeFromInput(array $input): ?AccountMode
    {
        $raw = strtolower(trim((string) ($input['account_mode'] ?? '')));
        if ($raw === '') {
            return null;
        }

        return AccountMode::tryFrom($raw);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function mobileIntentFromValidated(array $payload): ?MobileIntent
    {
        $mobile = is_array($payload['mobile_intent'] ?? null) ? (array) $payload['mobile_intent'] : [];
        if ($mobile === []) {
            return null;
        }

        $needsMobileAccess = $mobile['needs_mobile_access'] ?? null;
        if (! is_bool($needsMobileAccess)) {
            return null;
        }

        $roles = [];
        foreach ($this->stringList($mobile['mobile_roles_needed'] ?? []) as $value) {
            $role = MobileRole::tryFrom($value);
            if ($role instanceof MobileRole) {
                $roles[] = $role;
            }
        }

        $jobs = [];
        foreach ($this->stringList($mobile['mobile_jobs_requested'] ?? []) as $value) {
            $job = MobileJob::tryFrom($value);
            if ($job instanceof MobileJob) {
                $jobs[] = $job;
            }
        }

        return new MobileIntent(
            needsMobileAccess: $needsMobileAccess,
            rolesNeeded: $roles,
            jobsRequested: $jobs,
            priority: $this->nullableString($mobile['mobile_priority'] ?? null)
        );
    }

    /**
     * @return array<int,string>
     */
    protected function validMobileRoleValues(): array
    {
        return array_values(array_map(static fn (MobileRole $role): string => $role->value, MobileRole::cases()));
    }

    /**
     * @return array<int,string>
     */
    protected function validMobileJobValues(): array
    {
        return array_values(array_map(static fn (MobileJob $job): string => $job->value, MobileJob::cases()));
    }

    /**
     * @return array<int,string>
     */
    protected function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item): string {
            return strtolower(trim((string) $item));
        }, $value), static fn (string $item): bool => $item !== ''));
    }

    protected function nullableString(mixed $value): ?string
    {
        $raw = trim((string) $value);

        return $raw !== '' ? $raw : null;
    }
}
