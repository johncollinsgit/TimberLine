<?php

namespace App\Services\Tenancy;

use App\Models\TenantCommercialOverride;
use Illuminate\Support\Facades\Schema;

class TenantDisplayLabelResolver
{
    /**
     * @var array<string,array{
     *   tenant_id:?int,
     *   template_key:?string,
     *   source:string,
     *   labels:array<string,string>,
     *   module_labels:array<string,string>,
     *   token_map:array<string,string>,
     *   template_missing:bool
     * }>
     */
    protected array $resolvedCache = [];

    public function __construct(
        protected LandlordCommercialConfigService $commercialConfigService
    ) {
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   template_key:?string,
     *   source:string,
     *   labels:array<string,string>,
     *   module_labels:array<string,string>,
     *   token_map:array<string,string>,
     *   template_missing:bool
     * }
     */
    public function resolve(?int $tenantId): array
    {
        $cacheKey = $tenantId === null ? 'tenant:null' : 'tenant:'.$tenantId;
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $globalFallback = $this->globalFallbackLabels();

        if ($tenantId === null || ! Schema::hasTable('tenant_commercial_overrides')) {
            return $this->resolvedCache[$cacheKey] = $this->resolvedPayload(
                tenantId: $tenantId,
                templateKey: null,
                source: 'global_fallback',
                labels: $this->canonicalizedLabels($globalFallback),
                templateMissing: false
            );
        }

        $override = TenantCommercialOverride::query()
            ->forTenantId($tenantId)
            ->first();

        if (! $override) {
            return $this->resolvedCache[$cacheKey] = $this->resolvedPayload(
                tenantId: $tenantId,
                templateKey: null,
                source: 'global_fallback',
                labels: $this->canonicalizedLabels($globalFallback),
                templateMissing: false
            );
        }

        $templateKey = $this->nullableString($override->template_key);
        $explicitLabels = $this->normalizeLabels(
            is_array($override->display_labels) ? $override->display_labels : []
        );

        $templateExists = true;
        $templateLabels = [];

        if ($templateKey !== null) {
            $template = $this->commercialConfigService->templateByKey($templateKey);
            $templateExists = $template !== null;
            $templateLabels = $this->normalizeLabels(
                is_array($template['payload']['default_labels'] ?? null)
                    ? (array) $template['payload']['default_labels']
                    : []
            );
        }

        $source = 'global_fallback';
        if ($explicitLabels !== []) {
            $source = 'tenant_override';
        } elseif ($templateLabels !== []) {
            $source = 'template_default';
        }

        return $this->resolvedCache[$cacheKey] = $this->resolvedPayload(
            tenantId: $tenantId,
            templateKey: $templateKey,
            source: $source,
            labels: $this->canonicalizedLabels(array_replace($globalFallback, $templateLabels, $explicitLabels)),
            templateMissing: $templateKey !== null && ! $templateExists
        );
    }

    /**
     * @return array<string,string>
     */
    public function moduleLabels(?int $tenantId): array
    {
        return $this->resolve($tenantId)['module_labels'];
    }

    public function label(?int $tenantId, string $key, ?string $fallback = null): string
    {
        $labels = $this->resolve($tenantId)['labels'];

        $normalized = strtolower(trim($key));
        if ($normalized !== '' && isset($labels[$normalized]) && trim((string) $labels[$normalized]) !== '') {
            return (string) $labels[$normalized];
        }

        return $fallback !== null && trim($fallback) !== '' ? $fallback : ucfirst(str_replace('_', ' ', $normalized));
    }

    /**
     * @param  array<string,string>  $labels
     * @return array{
     *   tenant_id:?int,
     *   template_key:?string,
     *   source:string,
     *   labels:array<string,string>,
     *   module_labels:array<string,string>,
     *   token_map:array<string,string>,
     *   template_missing:bool
     * }
     */
    protected function resolvedPayload(
        ?int $tenantId,
        ?string $templateKey,
        string $source,
        array $labels,
        bool $templateMissing
    ): array {
        $moduleLabels = [
            'rewards' => (string) ($labels['rewards_label'] ?? 'Rewards'),
            'birthdays' => (string) ($labels['birthdays_label'] ?? 'Birthdays / Lifecycle'),
            'customers' => (string) ($labels['customers_label'] ?? (string) config('entitlements.modules.customers.label', 'Customers')),
            'campaigns' => (string) ($labels['campaigns_label'] ?? (string) config('entitlements.modules.campaigns.label', 'Campaigns')),
            'integrations' => (string) ($labels['integrations_label'] ?? (string) config('entitlements.modules.integrations.label', 'Integrations')),
            'settings' => (string) ($labels['settings_label'] ?? (string) config('entitlements.modules.settings.label', 'Settings')),
        ];

        // Maintain module-key compatibility for existing context consumers.
        $labels = [
            ...$labels,
            'rewards' => $moduleLabels['rewards'],
            'birthdays' => $moduleLabels['birthdays'],
            'customers' => $moduleLabels['customers'],
            'campaigns' => $moduleLabels['campaigns'],
            'integrations' => $moduleLabels['integrations'],
            'settings' => $moduleLabels['settings'],
        ];

        $tokenMap = [];
        foreach ($labels as $key => $value) {
            if (! is_string($key) || trim($key) === '' || trim((string) $value) === '') {
                continue;
            }

            $tokenMap['{{'.trim(strtolower($key)).'}}'] = trim((string) $value);
        }

        return [
            'tenant_id' => $tenantId,
            'template_key' => $templateKey,
            'source' => in_array($source, ['tenant_override', 'template_default', 'global_fallback'], true)
                ? $source
                : 'global_fallback',
            'labels' => $labels,
            'module_labels' => $moduleLabels,
            'token_map' => $tokenMap,
            'template_missing' => $templateMissing,
        ];
    }

    /**
     * @param  array<string,string>  $labels
     * @return array<string,string>
     */
    protected function canonicalizedLabels(array $labels): array
    {
        $rewardsLabel = $this->resolveLabel(
            $labels,
            ['rewards_label', 'rewards'],
            'Rewards'
        );

        $birthdaysLabel = $this->resolveLabel(
            $labels,
            ['birthdays_label', 'birthdays'],
            'Birthdays / Lifecycle'
        );

        $rewardCreditDefault = str_contains(strtolower($rewardsLabel), 'cash')
            ? $rewardsLabel.' credit'
            : 'reward credit';

        return [
            'rewards_label' => $rewardsLabel,
            'rewards_balance_label' => $this->resolveLabel(
                $labels,
                ['rewards_balance_label', 'rewards_balance'],
                $rewardsLabel.' balance'
            ),
            'rewards_program_label' => $this->resolveLabel(
                $labels,
                ['rewards_program_label', 'rewards_program'],
                $rewardsLabel.' program'
            ),
            'rewards_redemption_label' => $this->resolveLabel(
                $labels,
                ['rewards_redemption_label', 'rewards_redemption'],
                $rewardsLabel.' redemption'
            ),
            'reward_credit_label' => $this->resolveLabel(
                $labels,
                ['reward_credit_label', 'reward_credit'],
                $rewardCreditDefault
            ),
            'birthdays_label' => $birthdaysLabel,
            'birthday_reward_label' => $this->resolveLabel(
                $labels,
                ['birthday_reward_label', 'birthday_reward'],
                'Birthday reward'
            ),
            'customers_label' => $this->resolveLabel(
                $labels,
                ['customers_label', 'customers'],
                (string) config('entitlements.modules.customers.label', 'Customers')
            ),
            'campaigns_label' => $this->resolveLabel(
                $labels,
                ['campaigns_label', 'campaigns'],
                (string) config('entitlements.modules.campaigns.label', 'Campaigns')
            ),
            'integrations_label' => $this->resolveLabel(
                $labels,
                ['integrations_label', 'integrations'],
                (string) config('entitlements.modules.integrations.label', 'Integrations')
            ),
            'settings_label' => $this->resolveLabel(
                $labels,
                ['settings_label', 'settings'],
                (string) config('entitlements.modules.settings.label', 'Settings')
            ),
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function globalFallbackLabels(): array
    {
        return $this->normalizeLabels((array) config('commercial.display_label_defaults', []));
    }

    /**
     * @param  array<string,string>  $labels
     * @param  array<int,string>  $keys
     */
    protected function resolveLabel(array $labels, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized === '') {
                continue;
            }

            if (isset($labels[$normalized]) && trim((string) $labels[$normalized]) !== '') {
                return trim((string) $labels[$normalized]);
            }
        }

        return trim($fallback) !== '' ? trim($fallback) : 'Label';
    }

    /**
     * @param  array<mixed,mixed>  $labels
     * @return array<string,string>
     */
    protected function normalizeLabels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $labelKey = strtolower(trim((string) $key));
            if ($labelKey === '' || ctype_digit($labelKey)) {
                continue;
            }

            $labelValue = trim((string) $value);
            if ($labelValue === '') {
                continue;
            }

            $normalized[$labelKey] = $labelValue;
        }

        return $normalized;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
