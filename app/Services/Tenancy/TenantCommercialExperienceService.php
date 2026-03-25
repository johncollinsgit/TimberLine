<?php

namespace App\Services\Tenancy;

use App\Models\TenantAccessAddon;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Support\Tenancy\TenantModuleUi;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantCommercialExperienceService
{
    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected TenantEmailSettingsService $tenantEmailSettingsService,
        protected TwilioSenderConfigService $twilioSenderConfigService
    ) {
    }

    /**
     * @return array{promo:array<string,mixed>,plan_cards:array<int,array<string,mixed>>}
     */
    public function promoPayload(): array
    {
        $promo = (array) config('product_surfaces.promo', []);
        $planCards = $this->planCards(
            cardsConfig: (array) config('product_surfaces.plans.cards', []),
            preferredOrder: $this->normalizeKeys((array) ($promo['plan_order'] ?? [])),
            activePlanKey: null
        );

        return [
            'promo' => $promo,
            'plan_cards' => $planCards,
        ];
    }

    /**
     * @return array{
     *   content:array<string,mixed>,
     *   tenant_id:?int,
     *   plan:array{key:string,label:string,track:string,operating_mode:string},
     *   module_states:array<string,array<string,mixed>>,
     *   module_order:array<int,string>,
     *   checklist:array<string,mixed>,
     *   recommended_actions:array<int,array<string,mixed>>
     * }
     */
    public function onboardingPayload(?int $tenantId): array
    {
        $content = (array) config('product_surfaces.onboarding', []);
        $moduleOrder = $this->normalizeKeys((array) ($content['module_order'] ?? []));

        if ($moduleOrder === []) {
            $moduleOrder = array_keys((array) config('entitlements.modules', []));
        }

        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleOrder);
        $moduleStates = (array) ($resolved['modules'] ?? []);
        $checklist = TenantModuleUi::checklist($moduleStates, $moduleOrder);

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planDefinition = is_array(config('entitlements.plans.'.$planKey))
            ? (array) config('entitlements.plans.'.$planKey)
            : [];

        return [
            'content' => $content,
            'tenant_id' => $tenantId,
            'plan' => [
                'key' => $planKey,
                'label' => (string) ($planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
            ],
            'module_states' => $moduleStates,
            'module_order' => $moduleOrder,
            'checklist' => $checklist,
            'recommended_actions' => $this->recommendedActions(
                actions: (array) ($content['recommended_actions'] ?? []),
                moduleStates: $moduleStates
            ),
        ];
    }

    /**
     * @return array{
     *   content:array<string,mixed>,
     *   tenant_id:?int,
     *   current_plan:array<string,mixed>,
     *   module_states:array<string,array<string,mixed>>,
     *   checklist:array<string,mixed>,
     *   current_plan_modules:array<string,array<string,mixed>>,
     *   locked_modules:array<int,array<string,mixed>>,
     *   add_on_capable_modules:array<int,array<string,mixed>>,
     *   plan_cards:array<int,array<string,mixed>>,
     *   addon_cards:array<int,array<string,mixed>>,
     *   enabled_addon_keys:array<int,string>
     * }
     */
    public function plansPayload(?int $tenantId): array
    {
        $content = (array) config('product_surfaces.plans', []);
        $moduleCatalog = (array) config('entitlements.modules', []);
        $moduleKeys = array_keys($moduleCatalog);
        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleKeys);

        $moduleStates = (array) ($resolved['modules'] ?? []);
        $checklist = TenantModuleUi::checklist($moduleStates, $moduleKeys);

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planCatalog = (array) config('entitlements.plans', []);
        $currentPlan = is_array($planCatalog[$planKey] ?? null) ? (array) $planCatalog[$planKey] : [];
        $currentPlanIncludes = $this->normalizeKeys((array) ($currentPlan['includes'] ?? []));

        $currentPlanModules = [];
        foreach ($currentPlanIncludes as $moduleKey) {
            if (! isset($moduleStates[$moduleKey]) || ! is_array($moduleStates[$moduleKey])) {
                continue;
            }

            $currentPlanModules[$moduleKey] = $moduleStates[$moduleKey];
        }

        $enabledAddonKeys = $this->enabledAddonKeys($tenantId);

        $addonCatalog = (array) config('entitlements.addons', []);
        $addonCards = [];
        foreach ($addonCatalog as $addonKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $normalizedAddonKey = strtolower(trim((string) $addonKey));
            if ($normalizedAddonKey === '') {
                continue;
            }

            $contentDefinition = is_array(($content['addons'][$normalizedAddonKey] ?? null))
                ? (array) $content['addons'][$normalizedAddonKey]
                : [];
            $includeKeys = $this->normalizeKeys((array) ($definition['includes'] ?? []));

            $modules = array_map(function (string $moduleKey) use ($moduleStates): array {
                $state = is_array($moduleStates[$moduleKey] ?? null) ? $moduleStates[$moduleKey] : [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                    'coming_soon' => false,
                    'upgrade_prompt_eligible' => true,
                ];

                return [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'state' => TenantModuleUi::present($state, $this->moduleLabel($moduleKey)),
                ];
            }, $includeKeys);

            $addonCards[] = [
                'addon_key' => $normalizedAddonKey,
                'label' => (string) ($contentDefinition['name'] ?? $definition['label'] ?? Str::title(str_replace('_', ' ', $normalizedAddonKey))),
                'price_display' => (string) ($contentDefinition['price_display'] ?? 'Add-on pricing'),
                'summary' => (string) ($contentDefinition['summary'] ?? ''),
                'enabled' => in_array($normalizedAddonKey, $enabledAddonKeys, true),
                'modules' => $modules,
            ];
        }

        return [
            'content' => $content,
            'tenant_id' => $tenantId,
            'current_plan' => [
                'key' => $planKey,
                'label' => (string) ($currentPlan['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                'track' => (string) ($currentPlan['track'] ?? 'shopify'),
                'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
                'includes' => $currentPlanIncludes,
            ],
            'module_states' => $moduleStates,
            'checklist' => $checklist,
            'current_plan_modules' => $currentPlanModules,
            'locked_modules' => array_values((array) ($checklist['locked'] ?? [])),
            'add_on_capable_modules' => $this->addOnCapableModules($moduleStates, $addonCatalog),
            'plan_cards' => $this->planCards(
                cardsConfig: (array) ($content['cards'] ?? []),
                preferredOrder: $this->normalizeKeys((array) ($content['plan_order'] ?? [])),
                activePlanKey: $planKey
            ),
            'addon_cards' => $addonCards,
            'enabled_addon_keys' => $enabledAddonKeys,
        ];
    }

    /**
     * @return array{
     *   content:array<string,mixed>,
     *   tenant_id:?int,
     *   plan:array{key:string,label:string,track:string,operating_mode:string},
     *   module_states:array<string,array<string,mixed>>,
     *   cards:array<int,array<string,mixed>>,
     *   status_registry:array<string,array<string,mixed>>,
     *   categories:array<int,array{key:string,label:string,cards:array<int,array<string,mixed>>}>,
     *   counts:array{total:int,connected:int,setup_needed:int,locked:int,coming_soon:int}
     * }
     */
    public function integrationsPayload(?int $tenantId): array
    {
        $content = (array) config('product_surfaces.integrations', []);
        $cardsConfig = (array) ($content['cards'] ?? []);
        $moduleKeys = [];

        foreach ($cardsConfig as $card) {
            if (! is_array($card)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($card['module_key'] ?? '')));
            if ($moduleKey !== '') {
                $moduleKeys[] = $moduleKey;
            }
        }

        $moduleKeys = $this->normalizeKeys($moduleKeys);
        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleKeys);
        $moduleStates = (array) ($resolved['modules'] ?? []);
        $statusContext = $this->integrationStatusContext($tenantId);

        $cards = $this->integrationCards(
            cardsConfig: $cardsConfig,
            moduleStates: $moduleStates,
            content: $content,
            statusContext: $statusContext
        );
        $categoriesConfig = (array) ($content['categories'] ?? []);
        $groupedCards = [];
        foreach ($cards as $card) {
            $category = strtolower(trim((string) ($card['category'] ?? 'other')));
            if (! isset($groupedCards[$category])) {
                $groupedCards[$category] = [];
            }

            $groupedCards[$category][] = $card;
        }

        $categories = [];
        foreach ($categoriesConfig as $categoryKey => $categoryLabel) {
            $normalized = strtolower(trim((string) $categoryKey));
            if ($normalized === '' || ! isset($groupedCards[$normalized])) {
                continue;
            }

            $categories[] = [
                'key' => $normalized,
                'label' => (string) $categoryLabel,
                'cards' => $groupedCards[$normalized],
            ];
            unset($groupedCards[$normalized]);
        }

        foreach ($groupedCards as $categoryKey => $categoryCards) {
            $categories[] = [
                'key' => $categoryKey,
                'label' => $this->integrationCategoryLabel($categoryKey, $categoriesConfig),
                'cards' => $categoryCards,
            ];
        }

        $statusRegistry = [];
        foreach ($cards as $card) {
            $key = strtolower(trim((string) ($card['key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $statusRegistry[$key] = is_array($card['status_registry'] ?? null)
                ? (array) $card['status_registry']
                : [];
        }

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planDefinition = is_array(config('entitlements.plans.'.$planKey))
            ? (array) config('entitlements.plans.'.$planKey)
            : [];

        return [
            'content' => $content,
            'tenant_id' => $tenantId,
            'plan' => [
                'key' => $planKey,
                'label' => (string) ($planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
            ],
            'module_states' => $moduleStates,
            'cards' => $cards,
            'status_registry' => $statusRegistry,
            'categories' => $categories,
            'counts' => [
                'total' => count($cards),
                'connected' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'connected')),
                'setup_needed' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'setup_needed')),
                'locked' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'locked')),
                'coming_soon' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'coming_soon')),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $cardsConfig
     * @param  array<int,string>  $preferredOrder
     * @return array<int,array<string,mixed>>
     */
    protected function planCards(array $cardsConfig, array $preferredOrder, ?string $activePlanKey): array
    {
        $catalog = (array) config('entitlements.plans', []);
        $orderedKeys = $preferredOrder !== []
            ? $preferredOrder
            : $this->normalizeKeys(array_keys($cardsConfig));

        if ($orderedKeys === []) {
            $orderedKeys = $this->normalizeKeys(array_keys($catalog));
        }

        $cards = [];
        foreach ($orderedKeys as $planKey) {
            $planDefinition = is_array($catalog[$planKey] ?? null) ? (array) $catalog[$planKey] : [];
            $cardContent = is_array($cardsConfig[$planKey] ?? null) ? (array) $cardsConfig[$planKey] : [];

            if ($planDefinition === [] && $cardContent === []) {
                continue;
            }

            $includeKeys = $this->normalizeKeys((array) ($planDefinition['includes'] ?? []));
            $moduleLabels = array_map(fn (string $moduleKey): string => $this->moduleLabel($moduleKey), $includeKeys);

            $cards[] = [
                'plan_key' => $planKey,
                'label' => (string) ($cardContent['name'] ?? $planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey))),
                'price_display' => (string) ($cardContent['price_display'] ?? 'Custom pricing'),
                'summary' => (string) ($cardContent['summary'] ?? ''),
                'highlights' => array_values(array_map('strval', (array) ($cardContent['highlights'] ?? []))),
                'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                'modules' => $moduleLabels,
                'includes' => $includeKeys,
                'cta' => is_array($cardContent['cta'] ?? null) ? (array) $cardContent['cta'] : null,
                'is_current' => $activePlanKey !== null && $activePlanKey === $planKey,
            ];
        }

        return $cards;
    }

    /**
     * @param  array<int,array<string,mixed>>  $actions
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array<int,array<string,mixed>>
     */
    protected function recommendedActions(array $actions, array $moduleStates): array
    {
        $normalized = [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $title = trim((string) ($action['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($action['module_key'] ?? '')));
            $moduleState = null;
            if ($moduleKey !== '' && is_array($moduleStates[$moduleKey] ?? null)) {
                $moduleState = TenantModuleUi::present($moduleStates[$moduleKey], $this->moduleLabel($moduleKey));
            }

            $normalized[] = [
                'title' => $title,
                'description' => trim((string) ($action['description'] ?? '')),
                'href' => trim((string) ($action['href'] ?? '')),
                'module_key' => $moduleKey !== '' ? $moduleKey : null,
                'module_state' => $moduleState,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @param  array<string,mixed>  $addonCatalog
     * @return array<int,array<string,mixed>>
     */
    protected function addOnCapableModules(array $moduleStates, array $addonCatalog): array
    {
        $moduleKeys = [];
        foreach ($addonCatalog as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            foreach ($this->normalizeKeys((array) ($definition['includes'] ?? [])) as $moduleKey) {
                $moduleKeys[$moduleKey] = true;
            }
        }

        $rows = [];
        foreach (array_keys($moduleKeys) as $moduleKey) {
            $state = is_array($moduleStates[$moduleKey] ?? null)
                ? $moduleStates[$moduleKey]
                : [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                    'coming_soon' => false,
                    'upgrade_prompt_eligible' => true,
                ];

            $rows[] = TenantModuleUi::present($state, $this->moduleLabel($moduleKey));
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            strtolower(trim((string) ($left['label'] ?? ''))),
            strtolower(trim((string) ($right['label'] ?? '')))
        ));

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $cardsConfig
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @param  array<string,mixed>  $content
     * @return array<int,array<string,mixed>>
     */
    protected function integrationCards(
        array $cardsConfig,
        array $moduleStates,
        array $content,
        array $statusContext
    ): array
    {
        $cards = [];
        foreach ($cardsConfig as $cardKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $integrationKey = strtolower(trim((string) ($definition['key'] ?? $cardKey)));
            if ($integrationKey === '') {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($definition['module_key'] ?? 'integrations')));
            $moduleState = is_array($moduleStates[$moduleKey] ?? null)
                ? $moduleStates[$moduleKey]
                : [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                    'coming_soon' => false,
                    'upgrade_prompt_eligible' => true,
                ];
            $moduleStateUi = TenantModuleUi::present($moduleState, $this->moduleLabel($moduleKey));

            $availability = strtolower(trim((string) ($definition['availability'] ?? 'available')));
            if (! in_array($availability, ['available', 'locked', 'coming_soon'], true)) {
                $availability = 'available';
            }

            $fallbackMode = strtolower(trim((string) ($definition['fallback_mode'] ?? 'none')));
            if (! in_array($fallbackMode, ['manual_import', 'csv_upload', 'none'], true)) {
                $fallbackMode = 'none';
            }

            $statusDefinition = is_array($definition['status'] ?? null) ? (array) $definition['status'] : [];
            $connected = (bool) ($definition['mock_connected'] ?? false);
            if (! $connected && $this->integrationBuiltInConnected($integrationKey, $fallbackMode, $statusDefinition)) {
                $connected = true;
            }
            $state = $this->integrationResolvedState($availability, $moduleStateUi, $connected);
            $stateLabel = match ($state) {
                'connected' => 'Connected',
                'setup_needed' => 'Setup Needed',
                'locked' => 'Locked',
                default => 'Coming Soon',
            };

            $fallbackHref = trim((string) ($definition['fallback_href'] ?? ''));
            $fallbackLabel = $this->integrationFallbackLabel($fallbackMode);
            $ctas = is_array($definition['ctas'] ?? null) ? (array) $definition['ctas'] : [];
            $setup = is_array($definition['setup'] ?? null) ? (array) $definition['setup'] : [];
            $setupSteps = $this->normalizeStringList((array) ($setup['setup_steps'] ?? []));
            $requiredFields = $this->normalizeStringList((array) ($setup['required_fields'] ?? []));
            $fallbackOptions = $this->normalizeStringList((array) ($setup['fallback_options'] ?? []));
            $notes = $this->normalizeStringList((array) ($setup['notes'] ?? []));
            $upgradeMessage = trim((string) ($setup['upgrade_message'] ?? ''));

            if ($setupSteps === []) {
                $setupSteps = [
                    'Review module state and fallback options for this integration.',
                ];
            }
            if ($requiredFields === []) {
                $requiredFields = ['No required fields defined yet.'];
            }
            if ($fallbackOptions === []) {
                $fallbackOptions = [
                    'You can still use this system without this integration.',
                    $fallbackMode !== 'none'
                        ? $fallbackLabel
                        : 'Continue with manual operations until this integration is available.',
                ];
            }
            if ($upgradeMessage === '') {
                $upgradeMessage = 'Upgrade and module entitlement determine when this integration can be activated.';
            }

            $cta = $this->integrationCtaForState(
                integrationKey: $integrationKey,
                state: $state,
                ctas: $ctas,
                content: $content,
                fallbackMode: $fallbackMode,
                fallbackHref: $fallbackHref,
                fallbackLabel: $fallbackLabel
            );
            $statusRegistry = $this->integrationStatusRegistry(
                integrationKey: $integrationKey,
                state: $state,
                fallbackMode: $fallbackMode,
                statusDefinition: $statusDefinition,
                moduleStateUi: $moduleStateUi,
                statusContext: $statusContext
            );

            $cards[] = [
                'key' => $integrationKey,
                'module_key' => $moduleKey,
                'title' => (string) ($definition['title'] ?? Str::headline($integrationKey)),
                'description' => (string) ($definition['description'] ?? ''),
                'category' => strtolower(trim((string) ($definition['category'] ?? 'other'))),
                'availability' => $availability,
                'plan_requirement' => trim((string) ($definition['plan_requirement'] ?? '')),
                'state' => $state,
                'state_label' => $stateLabel,
                'connected' => $state === 'connected',
                'module_state' => $moduleStateUi,
                'fallback' => [
                    'mode' => $fallbackMode,
                    'label' => $fallbackLabel,
                    'href' => $fallbackHref !== '' ? $fallbackHref : null,
                    'available' => $fallbackMode !== 'none',
                ],
                'setup' => [
                    'setup_steps' => $setupSteps,
                    'required_fields' => $requiredFields,
                    'fallback_options' => $fallbackOptions,
                    'notes' => $notes,
                    'upgrade_message' => $upgradeMessage,
                ],
                'status_registry' => $statusRegistry,
                'cta' => $cta,
            ];
        }

        return $cards;
    }

    protected function integrationResolvedState(string $availability, array $moduleStateUi, bool $connected): string
    {
        if ($availability === 'coming_soon') {
            return 'coming_soon';
        }

        if ($availability === 'locked') {
            return 'locked';
        }

        if (($moduleStateUi['ui_state'] ?? '') === 'coming_soon') {
            return 'coming_soon';
        }

        if (($moduleStateUi['ui_state'] ?? '') === 'locked') {
            return 'locked';
        }

        return $connected ? 'connected' : 'setup_needed';
    }

    /**
     * @param  array<string,mixed>  $ctas
     * @param  array<string,mixed>  $content
     * @return array{label:string,href:string,kind:string}
     */
    protected function integrationCtaForState(
        string $integrationKey,
        string $state,
        array $ctas,
        array $content,
        string $fallbackMode,
        string $fallbackHref,
        string $fallbackLabel
    ): array {
        $upgradeCta = is_array($content['upgrade_cta'] ?? null) ? (array) $content['upgrade_cta'] : [];
        $contactCta = is_array($content['contact_cta'] ?? null) ? (array) $content['contact_cta'] : [];

        if ($state === 'locked') {
            return [
                'label' => trim((string) ($ctas['upgrade_label'] ?? $upgradeCta['label'] ?? 'Upgrade to unlock')),
                'href' => trim((string) ($upgradeCta['href'] ?? '/shopify/app/plans')),
                'kind' => 'upgrade',
            ];
        }

        if ($state === 'coming_soon') {
            return [
                'label' => trim((string) ($ctas['coming_soon_label'] ?? $contactCta['label'] ?? 'Learn more')),
                'href' => trim((string) ($contactCta['href'] ?? '/platform/contact?intent=integrations')),
                'kind' => 'coming_soon',
            ];
        }

        if ($state === 'setup_needed' && $fallbackMode !== 'none' && $fallbackHref !== '') {
            return [
                'label' => trim((string) ($ctas['manual_label'] ?? $fallbackLabel)),
                'href' => $fallbackHref,
                'kind' => 'fallback',
            ];
        }

        if ($state === 'connected') {
            return [
                'label' => trim((string) ($ctas['manage_label'] ?? 'Connected')),
                'href' => trim((string) ($fallbackHref !== '' ? $fallbackHref : '/shopify/app/integrations?integration='.$integrationKey)),
                'kind' => 'connected',
            ];
        }

        return [
            'label' => trim((string) ($ctas['connect_label'] ?? 'Connect (Placeholder)')),
            'href' => '/shopify/app/integrations?integration='.$integrationKey,
            'kind' => 'connect',
        ];
    }

    protected function integrationFallbackLabel(string $fallbackMode): string
    {
        return match ($fallbackMode) {
            'manual_import' => 'Import manually',
            'csv_upload' => 'Upload CSV fallback',
            default => 'No fallback configured',
        };
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   email:array<string,mixed>,
     *   sms:array{
     *     supported:bool,
     *     default_sender_key:?string,
     *     default_sender_label:?string
     *   }
     * }
     */
    protected function integrationStatusContext(?int $tenantId): array
    {
        $email = $this->tenantEmailSettingsService->resolvedForTenant($tenantId);
        $smsDefaultSender = $this->twilioSenderConfigService->defaultSender();

        return [
            'tenant_id' => $tenantId,
            'email' => $email,
            'sms' => [
                'supported' => $this->twilioSenderConfigService->smsSupported(),
                'default_sender_key' => $this->nullableString($smsDefaultSender['key'] ?? null),
                'default_sender_label' => $this->nullableString($smsDefaultSender['label'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $statusDefinition
     * @param  array<string,mixed>  $moduleStateUi
     * @param  array<string,mixed>  $statusContext
     * @return array{
     *   key:string,
     *   state:string,
     *   status_label:string,
     *   source_label:string,
     *   last_checked_at:?string,
     *   setup_mode:string,
     *   notes:array<int,string>,
     *   can_configure:bool,
     *   is_mocked:bool,
     *   configured_in_app:bool,
     *   using_fallback:bool,
     *   summary:string
     * }
     */
    protected function integrationStatusRegistry(
        string $integrationKey,
        string $state,
        string $fallbackMode,
        array $statusDefinition,
        array $moduleStateUi,
        array $statusContext
    ): array {
        $setupMode = $this->integrationSetupMode($integrationKey, $state, $fallbackMode, $statusDefinition);
        $configuredInApp = $this->integrationConfiguredInApp(
            integrationKey: $integrationKey,
            state: $state,
            setupMode: $setupMode,
            moduleStateUi: $moduleStateUi,
            statusContext: $statusContext
        );
        $sourceLabel = $this->integrationSourceLabel(
            integrationKey: $integrationKey,
            state: $state,
            setupMode: $setupMode,
            fallbackMode: $fallbackMode,
            configuredInApp: $configuredInApp,
            statusDefinition: $statusDefinition,
            statusContext: $statusContext
        );
        $lastCheckedAt = $this->integrationLastCheckedAt(
            integrationKey: $integrationKey,
            statusDefinition: $statusDefinition,
            statusContext: $statusContext
        );
        $usingFallback = in_array($setupMode, ['manual', 'csv'], true) || in_array($fallbackMode, ['manual_import', 'csv_upload'], true);

        $notes = $this->normalizeStringList((array) ($statusDefinition['notes'] ?? []));
        if ($state === 'coming_soon') {
            $notes[] = 'This connector is roadmap-visible only in the current phase.';
        } elseif ($state === 'locked') {
            $notes[] = 'Access is controlled by tenant entitlement and plan/add-on profile.';
        } elseif (! $configuredInApp) {
            $notes[] = 'Status is derived from local configuration and entitlement context only.';
        }
        $notes = array_values(array_unique($this->normalizeStringList($notes)));

        $statusLabel = match ($state) {
            'connected' => $configuredInApp ? 'Configured' : 'Available',
            'setup_needed' => 'Setup Needed',
            'locked' => 'Locked',
            default => 'Coming Soon',
        };

        $summary = match ($state) {
            'connected' => $usingFallback
                ? 'This integration path is ready through built-in fallback workflow.'
                : 'This integration is marked as configured in local app context.',
            'setup_needed' => $usingFallback
                ? 'Manual/CSV fallback is available while setup remains incomplete.'
                : 'Connector setup is still required before this path is considered configured.',
            'locked' => 'Unavailable for this tenant profile until plan/add-on access changes.',
            default => 'Roadmap placeholder. No live connector behavior is active yet.',
        };

        return [
            'key' => $integrationKey,
            'state' => $state,
            'status_label' => $statusLabel,
            'source_label' => $sourceLabel,
            'last_checked_at' => $lastCheckedAt,
            'setup_mode' => $setupMode,
            'notes' => $notes,
            'can_configure' => in_array($state, ['connected', 'setup_needed'], true),
            'is_mocked' => $this->integrationIsMocked($integrationKey, $statusDefinition, $setupMode),
            'configured_in_app' => $configuredInApp,
            'using_fallback' => $usingFallback,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     */
    protected function integrationBuiltInConnected(string $integrationKey, string $fallbackMode, array $statusDefinition): bool
    {
        if (array_key_exists('built_in_connected', $statusDefinition)) {
            return (bool) $statusDefinition['built_in_connected'];
        }

        if ($integrationKey === 'manual_entry') {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     */
    protected function integrationSetupMode(
        string $integrationKey,
        string $state,
        string $fallbackMode,
        array $statusDefinition
    ): string {
        $configuredMode = strtolower(trim((string) ($statusDefinition['setup_mode'] ?? '')));
        if (in_array($configuredMode, ['manual', 'csv', 'direct', 'placeholder'], true)) {
            return $configuredMode;
        }

        if ($state === 'coming_soon') {
            return 'placeholder';
        }

        if ($fallbackMode === 'manual_import') {
            return 'manual';
        }

        if ($fallbackMode === 'csv_upload' || $integrationKey === 'csv_import') {
            return 'csv';
        }

        return 'direct';
    }

    /**
     * @param  array<string,mixed>  $moduleStateUi
     * @param  array<string,mixed>  $statusContext
     */
    protected function integrationConfiguredInApp(
        string $integrationKey,
        string $state,
        string $setupMode,
        array $moduleStateUi,
        array $statusContext
    ): bool {
        if (! in_array($state, ['connected', 'setup_needed'], true)) {
            return false;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            $email = is_array($statusContext['email'] ?? null) ? $statusContext['email'] : [];
            $providerStatus = strtolower(trim((string) ($email['provider_status'] ?? 'not_configured')));
            $enabled = (bool) ($email['email_enabled'] ?? false);

            return $enabled && $providerStatus === 'configured';
        }

        if ($integrationKey === 'sms_gateway') {
            return (bool) data_get($statusContext, 'sms.supported', false);
        }

        if ($integrationKey === 'manual_entry') {
            return true;
        }

        if ($setupMode === 'csv') {
            return false;
        }

        return strtolower(trim((string) ($moduleStateUi['setup_status'] ?? ''))) === 'configured'
            || $state === 'connected';
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     * @param  array<string,mixed>  $statusContext
     */
    protected function integrationSourceLabel(
        string $integrationKey,
        string $state,
        string $setupMode,
        string $fallbackMode,
        bool $configuredInApp,
        array $statusDefinition,
        array $statusContext
    ): string {
        if ($state === 'locked') {
            return 'Plan entitlement';
        }

        if ($state === 'coming_soon') {
            return 'Roadmap placeholder';
        }

        $configured = trim((string) ($statusDefinition['source_label'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            $source = strtolower(trim((string) data_get($statusContext, 'email.source', 'config_fallback')));

            return $source === 'tenant_email_settings'
                ? 'Tenant email settings'
                : 'Fallback email config';
        }

        if ($integrationKey === 'sms_gateway') {
            if ((bool) data_get($statusContext, 'sms.supported', false)) {
                $senderLabel = trim((string) data_get($statusContext, 'sms.default_sender_label', ''));

                return $senderLabel !== ''
                    ? 'Twilio sender · '.$senderLabel
                    : 'Twilio sender configuration';
            }

            return 'No live SMS sender configured';
        }

        if ($setupMode === 'manual') {
            return $configuredInApp ? 'Built-in manual workflow' : 'Manual workflow';
        }

        if ($setupMode === 'csv') {
            return 'CSV upload fallback';
        }

        if ($integrationKey === 'shopify_orders') {
            return 'Shopify embedded app context';
        }

        if ($fallbackMode === 'none' && ! $configuredInApp) {
            return 'Placeholder direct connector';
        }

        return 'Direct connector profile';
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     * @param  array<string,mixed>  $statusContext
     */
    protected function integrationLastCheckedAt(
        string $integrationKey,
        array $statusDefinition,
        array $statusContext
    ): ?string {
        $configured = $this->nullableString($statusDefinition['last_checked_at'] ?? null);
        if ($configured !== null) {
            return $configured;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            return $this->nullableString(data_get($statusContext, 'email.last_tested_at'));
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     */
    protected function integrationIsMocked(string $integrationKey, array $statusDefinition, string $setupMode): bool
    {
        if (array_key_exists('is_mocked', $statusDefinition)) {
            return (bool) $statusDefinition['is_mocked'];
        }

        if (in_array($integrationKey, ['manual_entry', 'csv_import', 'sms_gateway'], true)) {
            return false;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            return false;
        }

        return $setupMode !== 'manual' && $setupMode !== 'csv';
    }

    /**
     * @param  array<string,mixed>  $categories
     */
    protected function integrationCategoryLabel(string $categoryKey, array $categories): string
    {
        $resolved = strtolower(trim($categoryKey));

        if ($resolved !== '' && array_key_exists($resolved, $categories)) {
            return (string) $categories[$resolved];
        }

        return Str::headline($resolved === '' ? 'other' : $resolved);
    }

    /**
     * @return array<int,string>
     */
    protected function enabledAddonKeys(?int $tenantId): array
    {
        if ($tenantId === null || ! Schema::hasTable('tenant_access_addons')) {
            return [];
        }

        return TenantAccessAddon::query()
            ->forTenantId($tenantId)
            ->where('enabled', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->pluck('addon_key')
            ->map(static fn ($value): string => strtolower(trim((string) $value)))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function moduleLabel(string $moduleKey): string
    {
        $key = strtolower(trim($moduleKey));

        return (string) data_get(
            config('entitlements.modules', []),
            $key.'.label',
            Str::title(str_replace('_', ' ', $key))
        );
    }

    /**
     * @param  array<int,mixed>  $keys
     * @return array<int,string>
     */
    protected function normalizeKeys(array $keys): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $keys
        ))));
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    protected function normalizeStringList(array $items): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $items
        ), static fn (string $item): bool => $item !== ''));
    }

    protected function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
