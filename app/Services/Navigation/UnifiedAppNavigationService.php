<?php

namespace App\Services\Navigation;

use App\Models\MappingException;
use App\Models\ShopifyImportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Birthdays\BirthdaySectionRegistry;
use App\Support\Marketing\MarketingSectionRegistry;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class UnifiedAppNavigationService
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected TenantHostBuilder $tenantHostBuilder
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Request $request, ?User $user = null): array
    {
        $user ??= $request->user();

        if ($this->isLandlordShell($request)) {
            return $this->buildLandlordShell($request, $user);
        }

        $attributeTenant = $request->attributes->get('current_tenant');
        $tenant = $attributeTenant instanceof Tenant
            ? $attributeTenant
            : ($user ? $this->tenantContextResolver->resolveForRequest($request, $user) : null);
        $tenantId = $tenant ? (int) $tenant->id : null;
        $profile = $this->experienceProfileService->forTenant($tenantId, $user, $tenant);

        $isAdmin = $user?->isAdmin() ?? true;
        $isManager = $user?->isManager() ?? false;
        $isPouring = $user?->isPouring() ?? false;
        $canAccessOps = $isAdmin || $isManager;
        $roleCanAccessMarketing = $user?->canAccessMarketing() ?? false;

        $moduleStates = $tenantId !== null
            ? (array) ($this->moduleAccessResolver->resolveForTenant($tenantId, ['birthdays', 'customers', 'campaigns', 'wishlist', 'reporting', 'rewards', 'reviews', 'field_service', 'class_scheduling', 'plant_inventory', 'messaging'])['modules'] ?? [])
            : [];
        $fieldServiceEnabled = $this->moduleStateEnabled($moduleStates['field_service'] ?? null);
        $classSchedulingEnabled = $this->moduleStateEnabled($moduleStates['class_scheduling'] ?? null);
        $plantInventoryEnabled = $this->moduleStateEnabled($moduleStates['plant_inventory'] ?? null);
        $customersEnabled = $this->moduleStateEnabled($moduleStates['customers'] ?? null);
        $messagingRelevant = $this->moduleStateRelevant($moduleStates['messaging'] ?? null);
        $marketingHeavyEnabled = collect(['birthdays', 'campaigns', 'wishlist', 'rewards', 'reviews'])
            ->contains(fn (string $key): bool => $this->moduleStateEnabled($moduleStates[$key] ?? null));
        $isFlagshipTenant = $this->isFlagshipTenant($tenant);
        $canAccessMarketing = $roleCanAccessMarketing && (! $fieldServiceEnabled || $marketingHeavyEnabled || $isFlagshipTenant);

        $homeHref = $canAccessOps || $canAccessMarketing
            ? route('dashboard')
            : ($isPouring ? route('pouring.index') : route('wiki.index'));

        $items = [];
        $items[] = ['key' => 'home', 'icon' => 'home', 'href' => $homeHref, 'label' => 'Home', 'current' => request()->routeIs('dashboard')];

        if ($canAccessMarketing) {
            $birthdaysRelevant = $tenantId === null
                || $this->moduleStateRelevant($moduleStates['birthdays'] ?? null);

            $marketingChildren = $this->marketingNavigationChildren($tenantId !== null, $birthdaysRelevant);
            $marketingCurrent = collect($marketingChildren)->contains(
                fn (array $child): bool => (bool) ($child['current'] ?? false)
            );

            $items[] = [
                'key' => 'marketing',
                'icon' => 'users',
                'href' => route('marketing.overview'),
                'label' => 'Marketing',
                'current' => $marketingCurrent,
                'children' => $marketingChildren,
            ];
        }

        if ($canAccessOps) {
            $workItems = [];

            if ($classSchedulingEnabled && Route::has('class-scheduling.index')) {
                $workItems[] = [
                    'key' => 'class-scheduling',
                    'icon' => 'calendar-days',
                    'href' => route('class-scheduling.index'),
                    'label' => $this->isFrontYardFoodsTenant($tenant) ? 'Events & Classes' : 'Classes',
                    'current' => request()->routeIs('class-scheduling.*'),
                    'children' => [
                        ['key' => 'class-scheduling-calendar', 'icon' => 'calendar-days', 'href' => route('class-scheduling.index'), 'label' => 'Calendar', 'current' => request()->routeIs('class-scheduling.index')],
                        ['key' => 'class-scheduling-settings', 'icon' => 'cog-6-tooth', 'href' => route('class-scheduling.index').'#class-settings', 'label' => $this->isFrontYardFoodsTenant($tenant) ? 'Signup & publishing' : 'Signup settings', 'current' => false],
                    ],
                ];
            }

            if ($plantInventoryEnabled && Route::has('plant-inventory.index')) {
                $workItems[] = [
                    'key' => 'plant-inventory',
                    'icon' => 'archive-box',
                    'href' => route('plant-inventory.index'),
                    'label' => 'Plant Inventory',
                    'current' => request()->routeIs('plant-inventory.*'),
                ];
            }

            if ($fieldServiceEnabled && Route::has('field-service.index')) {
                $fieldServiceChildren = [
                    ['key' => 'field-service-jobs', 'icon' => 'briefcase', 'href' => route('field-service.index'), 'label' => 'Jobs', 'current' => request()->routeIs('field-service.*')],
                    ['key' => 'field-service-materials', 'icon' => 'archive-box', 'href' => route('field-service.index').'#materials', 'label' => 'Materials', 'current' => false],
                    ['key' => 'field-service-vehicles', 'icon' => 'truck', 'href' => route('field-service.index').'#vehicles', 'label' => 'Work vans', 'current' => false],
                ];

                $workItems[] = [
                    'key' => 'field-service',
                    'icon' => 'briefcase',
                    'href' => route('field-service.index'),
                    'label' => 'Work',
                    'current' => request()->routeIs('field-service.*'),
                    'children' => $fieldServiceChildren,
                ];
            }

            if ($isFlagshipTenant) {
                $productionChildren = [
                    ['key' => 'retail-plan', 'icon' => 'clipboard-document', 'href' => route('retail.plan'), 'label' => 'Pour Lists', 'current' => request()->routeIs('retail.plan')],
                    ['key' => 'events', 'icon' => 'calendar-days', 'href' => route('events.index'), 'label' => 'Events', 'current' => request()->routeIs('events.*')],
                    ['key' => 'shipping', 'icon' => 'truck', 'href' => route('shipping.orders'), 'label' => 'Shipping', 'current' => request()->routeIs('shipping.*')],
                    ['key' => 'pouring', 'icon' => 'beaker', 'href' => route('pouring.index'), 'label' => 'Pouring', 'current' => request()->routeIs('pouring.*')],
                    ['key' => 'markets', 'icon' => 'shopping-bag', 'href' => route('markets.browser.index'), 'label' => 'Markets', 'current' => request()->routeIs('markets.browser.*')],
                    ['key' => 'inventory', 'icon' => 'archive-box', 'href' => route('inventory.index'), 'label' => 'Inventory', 'current' => request()->routeIs('inventory.*')],
                ];
                $productionCurrent = collect($productionChildren)->contains(
                    fn (array $child): bool => (bool) ($child['current'] ?? false)
                );

                $workItems[] = ['key' => 'production', 'icon' => 'briefcase', 'href' => route('retail.plan'), 'label' => 'Work', 'current' => $productionCurrent, 'children' => $productionChildren];
                $workItems[] = ['key' => 'analytics', 'icon' => 'chart-bar', 'href' => route('analytics.index'), 'label' => 'Reports', 'current' => request()->routeIs('analytics.*')];
            }

            $opsItems = $workItems;

            if ($this->isFrontYardFoodsTenant($tenant)) {
                if ($customersEnabled && Route::has('marketing.customers')) {
                    $opsItems[] = [
                        'key' => 'front-yard-customers',
                        'icon' => 'users',
                        'href' => route('marketing.customers'),
                        'label' => 'Customers',
                        'current' => request()->routeIs('marketing.customers*'),
                    ];
                }

                if ($messagingRelevant && Route::has('marketing.messages')) {
                    $opsItems[] = [
                        'key' => 'front-yard-messaging',
                        'icon' => 'chat-bubble-left-right',
                        'href' => route('marketing.messages'),
                        'label' => 'Messaging · pending',
                        'current' => request()->routeIs('marketing.messages*'),
                    ];
                }
            }

            $prioritizeGrowth = in_array($profile['use_case_profile'] ?? 'ops', ['marketing', 'crm', 'hybrid'], true);
            $items = $prioritizeGrowth
                ? array_merge($items, $opsItems)
                : array_merge(array_slice($items, 0, 1), $opsItems, array_slice($items, 1));
        } elseif ($isPouring) {
            $items[] = ['key' => 'pouring', 'icon' => 'beaker', 'href' => route('pouring.index'), 'label' => 'Pouring', 'current' => request()->routeIs('pouring.*')];
        }

        if ($canAccessOps) {
            $items[] = ['key' => 'administration', 'icon' => 'cog-6-tooth', 'href' => route('admin.index'), 'label' => 'Settings', 'current' => request()->routeIs('admin.*')];
            if ($tenantId !== null && Route::has('agreements.index')) {
                $items[] = ['key' => 'user-agreements', 'icon' => 'document-check', 'href' => route('agreements.index', ['tenant' => $tenant?->slug]), 'label' => 'User Agreements', 'current' => request()->routeIs('agreements.*')];
            }
        }

        $items[] = ['key' => 'backstage-wiki', 'icon' => 'book-open', 'href' => route('wiki.index'), 'label' => 'Workspace Guide', 'current' => request()->routeIs('wiki.*')];

        $items = $this->normalizeNavigationItems($items);

        $prefs = is_array($user?->ui_preferences ?? null) ? $user->ui_preferences : [];
        $preferredSidebarOrder = is_array($prefs['sidebar_order'] ?? null) ? $prefs['sidebar_order'] : [];
        $items = $this->orderedItems($items, $preferredSidebarOrder);

        $adminSubItems = $canAccessOps ? $this->adminSubItems($isAdmin, $tenant) : [];
        $marketingSubGroups = $canAccessMarketing ? $this->marketingSubGroups() : [];
        $birthdaySubGroups = $canAccessMarketing ? $this->birthdaySubGroups() : [];

        return [
            'tenant' => $tenant,
            'tenant_id' => $tenantId,
            'experience_profile' => $profile,
            'items' => $items,
            'admin_sub_items' => $adminSubItems,
            'marketing_sub_groups' => $marketingSubGroups,
            'birthday_sub_groups' => $birthdaySubGroups,
            'wiki_sections' => $this->wikiSections($tenant),
            'quick_actions' => $this->quickActions($profile, $canAccessOps, $canAccessMarketing, $tenantId, $fieldServiceEnabled),
            'ops_attention' => $canAccessOps ? $this->opsAttention($tenantId) : ['unresolved_exceptions' => 0, 'latest_run' => null],
            'current_console' => $this->currentConsolePayload($tenant, $profile),
            'console_switches' => $this->consoleSwitches($user, $tenant, false),
            'shell_context' => 'tenant',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildLandlordShell(Request $request, ?User $user = null): array
    {
        $items = [
            ['key' => 'home', 'icon' => 'home', 'href' => route('landlord.dashboard'), 'label' => 'Home', 'current' => $request->routeIs('landlord.dashboard')],
            ['key' => 'workspaces', 'icon' => 'building-office-2', 'href' => route('landlord.tenants.index'), 'label' => 'Workspaces', 'current' => $request->routeIs('landlord.tenants.*')],
            ['key' => 'access-requests', 'icon' => 'inbox', 'href' => route('landlord.onboarding.intake'), 'label' => 'Access Requests', 'current' => $request->routeIs('landlord.onboarding.intake')],
            ['key' => 'setup-reviews', 'icon' => 'clipboard-document-check', 'href' => route('landlord.onboarding.journey'), 'label' => 'Setup Reviews', 'current' => $request->routeIs('landlord.onboarding.journey') || $request->routeIs('landlord.onboarding.wizard')],
            ['key' => 'features', 'icon' => 'squares-plus', 'href' => route('landlord.commercial.index'), 'label' => 'Features', 'current' => $request->routeIs('landlord.commercial.*')],
            ['key' => 'custom-requests', 'icon' => 'chat-bubble-left-right', 'href' => route('landlord.custom-module-requests.index'), 'label' => 'Custom Requests', 'current' => $request->routeIs('landlord.custom-module-requests.*')],
            ['key' => 'plan-billing-readiness', 'icon' => 'credit-card', 'href' => route('landlord.commercial-intent.index'), 'label' => 'Plan / Billing Readiness', 'current' => $request->routeIs('landlord.commercial-intent.*')],
            ['key' => 'agreements', 'icon' => 'document-check', 'href' => route('landlord.agreements.index'), 'label' => 'Agreements', 'current' => $request->routeIs('landlord.agreements.*')],
            ['key' => 'shopify-readiness', 'icon' => 'shopping-bag', 'href' => route('landlord.readiness').'#shopify-app-readiness', 'label' => 'Shopify Readiness', 'current' => false],
            ['key' => 'system-readiness', 'icon' => 'shield-check', 'href' => route('landlord.readiness'), 'label' => 'System Readiness', 'current' => $request->routeIs('landlord.readiness')],
            ['key' => 'developer', 'icon' => 'command-line', 'href' => route('landlord.developer'), 'label' => 'Developer', 'current' => $request->routeIs('landlord.developer')],
            ['key' => 'settings', 'icon' => 'cog-6-tooth', 'href' => route('landlord.dashboard'), 'label' => 'Settings', 'current' => false],
        ];

        return [
            'tenant' => null,
            'tenant_id' => null,
            'experience_profile' => [
                'tenant_name' => 'Everbranch Admin',
                'account_mode' => 'landlord',
                'channel_type' => 'operator',
                'use_case_profile' => 'admin',
                'workspace' => [
                    'label' => 'Everbranch Admin',
                    'subtitle' => 'Operator controls for workspaces, setup, readiness, and safe launch decisions.',
                    'command_placeholder' => 'Search or ask what you want to do...',
                ],
            ],
            'items' => $this->orderedItems($this->normalizeNavigationItems($items), []),
            'admin_sub_items' => [],
            'marketing_sub_groups' => [],
            'birthday_sub_groups' => [],
            'wiki_sections' => [],
            'quick_actions' => [
                [
                    'label' => 'Create workspace',
                    'description' => 'Start a safe setup plan without billing or module activation.',
                    'href' => route('landlord.tenants.create'),
                ],
                [
                    'label' => 'Review setup',
                    'description' => 'Check intake, next steps, and readiness blockers.',
                    'href' => route('landlord.onboarding.journey'),
                ],
            ],
            'ops_attention' => ['unresolved_exceptions' => 0, 'latest_run' => null],
            'current_console' => [
                'label' => 'Everbranch Admin',
                'descriptor' => 'Operator console',
            ],
            'console_switches' => $this->consoleSwitches($user, null, true),
            'shell_context' => 'landlord',
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array{label:string,descriptor:string}
     */
    protected function currentConsolePayload(?Tenant $tenant, array $profile): array
    {
        $label = $tenant instanceof Tenant
            ? trim((string) $tenant->name)
            : trim((string) ($profile['tenant_name'] ?? 'Workspace'));

        if ($label === '') {
            $label = trim((string) data_get($profile, 'workspace.label', 'Workspace'));
        }

        return [
            'label' => $label !== '' ? $label : 'Workspace',
            'descriptor' => 'Tenant console',
        ];
    }

    /**
     * @return array<int,array{key:string,label:string,descriptor:string,href:string,active:bool}>
     */
    protected function consoleSwitches(?User $user, ?Tenant $currentTenant = null, bool $isLandlordShell = false): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $switches = [];

        if ($this->canAccessLandlordConsole($user)) {
            $landlordPath = route('landlord.dashboard', absolute: false);
            $landlordHref = $this->tenantHostBuilder->canonicalLandlordUrlForPath($landlordPath) ?? $landlordPath;

            $switches[] = [
                'key' => 'landlord',
                'label' => 'Everbranch Admin',
                'descriptor' => 'Operator console',
                'href' => $landlordHref,
                'active' => $isLandlordShell,
            ];
        }

        $memberships = $user->tenants()
            ->with(['setupStatus:id,tenant_id,landlord_review_status'])
            ->orderBy('tenants.name')
            ->get(['tenants.id', 'tenants.name', 'tenants.slug']);

        foreach ($memberships as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $tenantPath = $this->tenantConsolePath($tenant);
            $tenantHost = filled($tenant->slug) ? $this->tenantHostBuilder->hostForSlug((string) $tenant->slug) : null;
            $tenantHref = $tenantHost !== null
                ? ($this->tenantHostBuilder->urlForHostPath($tenantHost, $tenantPath) ?? $tenantPath)
                : $tenantPath;

            $switches[] = [
                'key' => 'tenant-'.$tenant->id,
                'label' => trim((string) $tenant->name) !== '' ? trim((string) $tenant->name) : 'Workspace',
                'descriptor' => 'Tenant console',
                'href' => $tenantHref,
                'active' => ! $isLandlordShell && $currentTenant instanceof Tenant && (int) $currentTenant->id === (int) $tenant->id,
            ];
        }

        return collect($switches)
            ->unique('key')
            ->values()
            ->all();
    }

    protected function canAccessLandlordConsole(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-landlord-commercial');
    }

    protected function tenantConsolePath(Tenant $tenant): string
    {
        $tenant->loadMissing('setupStatus');

        $path = (string) ($tenant->setupStatus?->landlord_review_status ?? '') === 'reviewed'
            ? route('dashboard', absolute: false)
            : route('app.start', absolute: false);

        $slug = trim((string) ($tenant->slug ?? ''));
        if ($slug !== '') {
            $separator = str_contains($path, '?') ? '&' : '?';
            $path .= $separator.'tenant='.$slug;
        }

        return $path;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,mixed>  $preferredOrder
     * @return array<int,array<string,mixed>>
     */
    protected function orderedItems(array $items, array $preferredOrder): array
    {
        $itemsByKey = collect($items)
            ->mapWithKeys(function (array $item): array {
                $key = $this->normalizeSidebarOrderKey((string) ($item['key'] ?? ''));
                if ($key === '') {
                    return [];
                }

                return [$key => $item];
            });
        $orderedKeys = [];

        foreach ($preferredOrder as $key) {
            if (! is_string($key)) {
                continue;
            }

            $normalizedKey = $this->normalizeSidebarOrderKey($key);
            if ($normalizedKey !== '' && $itemsByKey->has($normalizedKey) && ! in_array($normalizedKey, $orderedKeys, true)) {
                $orderedKeys[] = $normalizedKey;
            }
        }

        foreach ($items as $item) {
            $normalizedKey = $this->normalizeSidebarOrderKey((string) ($item['key'] ?? ''));
            if ($normalizedKey !== '' && ! in_array($normalizedKey, $orderedKeys, true)) {
                $orderedKeys[] = $normalizedKey;
            }
        }

        if (($homeIndex = array_search('home', $orderedKeys, true)) !== false) {
            unset($orderedKeys[$homeIndex]);
            array_unshift($orderedKeys, 'home');
            $orderedKeys = array_values($orderedKeys);
        }

        return collect($orderedKeys)
            ->map(fn (string $key): ?array => $itemsByKey->get($key))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeNavigationItems(array $items): array
    {
        return collect($items)
            ->map(function (array $item): array {
                $children = collect((array) ($item['children'] ?? []))
                    ->filter(fn (mixed $child): bool => is_array($child))
                    ->map(function (array $child): array {
                        unset($child['children']);

                        return $child;
                    })
                    ->values()
                    ->all();

                if ($children === []) {
                    unset($item['children']);
                } else {
                    $item['children'] = $children;
                }

                return $item;
            })
            ->values()
            ->all();
    }

    protected function normalizeSidebarOrderKey(string $key): string
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return '';
        }

        $legacyMap = [
            'operations' => 'production',
            'shipping-room' => 'production',
            'pouring-room' => 'production',
            'retail-plan' => 'production',
            'pour-lists' => 'production',
            'events' => 'production',
            'markets' => 'production',
            'inventory' => 'production',
        ];

        return $legacyMap[$normalized] ?? $normalized;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function adminSubItems(bool $isAdmin, ?Tenant $tenant = null): array
    {
        $adminActive = request()->routeIs('admin.*') || request()->is('admin*');
        $adminTab = is_string(request()->query('tab')) ? (string) request()->query('tab') : '';

        if (! $this->isFlagshipTenant($tenant)) {
            return $isAdmin ? [[
                'key' => 'users',
                'label' => 'Team Access',
                'href' => route('admin.index', ['tab' => 'users']),
                'current' => $adminActive && $adminTab === 'users',
            ]] : [];
        }

        return [
            [
                'key' => 'master-data',
                'label' => 'Data Manager',
                'href' => route('admin.index', ['tab' => 'master-data', 'resource' => (string) request()->query('resource', 'scents') ?: 'scents']),
                'current' => $adminActive && $adminTab === 'master-data',
            ],
            ...($isAdmin ? [[
                'key' => 'users',
                'label' => 'Team Access',
                'href' => route('admin.index', ['tab' => 'users']),
                'current' => $adminActive && $adminTab === 'users',
            ]] : []),
            [
                'key' => 'imports',
                'label' => 'Import Issues',
                'href' => route('admin.index', ['tab' => 'imports']),
                'current' => $adminActive && $adminTab === 'imports',
            ],
            [
                'key' => 'scent-intake',
                'label' => 'New Scent Requests',
                'href' => route('admin.index', ['tab' => 'scent-intake']),
                'current' => $adminActive && $adminTab === 'scent-intake',
            ],
            [
                'key' => 'catalog',
                'label' => 'Product Catalog',
                'href' => route('admin.index', ['tab' => 'catalog']),
                'current' => $adminActive && $adminTab === 'catalog',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function marketingSubGroups(): array
    {
        $items = collect(MarketingSectionRegistry::sections())
            ->map(function (array $section, string $key): array {
                return [
                    'key' => $key,
                    'label' => $section['label'],
                    'href' => route($section['route']),
                    'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'].'.*'),
                ];
            })
            ->values()
            ->all();

        return MarketingSectionRegistry::groupNavigationItems($items);
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function marketingNavigationChildren(bool $includeFeatures, bool $includeBirthdays): array
    {
        $items = collect(MarketingSectionRegistry::sections())
            ->reject(fn (array $section, string $key): bool => $key === 'modules' && ! $includeFeatures)
            ->map(function (array $section, string $key): array {
                $label = $key === 'modules'
                    ? 'Features'
                    : (string) $section['label'];

                return [
                    'key' => $key,
                    'label' => $label,
                    'href' => route($section['route']),
                    'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'].'.*'),
                ];
            })
            ->values();

        if ($includeBirthdays) {
            $items->push([
                'key' => 'birthdays',
                'label' => 'Birthdays',
                'href' => route('birthdays.customers'),
                'current' => request()->routeIs('birthdays.*'),
            ]);
        }

        $preferredOrder = [
            'overview',
            'customers',
            'birthdays',
            'modules',
            'providers-integrations',
            'messages',
            'groups',
            'segments',
            'campaigns',
            'automations',
            'message-templates',
            'recommendations',
            'candle-cash',
            'reviews',
            'wishlist',
            'suppression-consent',
            'identity-review',
            'orders',
            'settings',
        ];

        $orderLookup = array_flip($preferredOrder);

        return $items
            ->sortBy(function (array $item) use ($orderLookup): array {
                return [
                    $orderLookup[$item['key']] ?? 999,
                    strtolower((string) ($item['label'] ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function birthdaySubGroups(): array
    {
        $items = collect(BirthdaySectionRegistry::sections())
            ->map(function (array $section, string $key): array {
                return [
                    'key' => $key,
                    'label' => $section['label'],
                    'href' => route($section['route']),
                    'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'].'.*'),
                ];
            })
            ->values()
            ->all();

        return BirthdaySectionRegistry::groupNavigationItems($items);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function wikiSections(?Tenant $tenant): array
    {
        if (! $this->isFlagshipTenant($tenant)) {
            return [
                [
                    'key' => 'workspace-overview',
                    'label' => 'Workspace Overview',
                    'href' => route('wiki.article', ['slug' => 'workspace-overview']),
                    'current' => request()->routeIs('wiki.article') && request()->route('slug') === 'workspace-overview',
                ],
                [
                    'key' => 'customers-and-jobs',
                    'label' => 'Customers & Jobs',
                    'href' => route('wiki.article', ['slug' => 'customers-and-jobs']),
                    'current' => request()->routeIs('wiki.article') && request()->route('slug') === 'customers-and-jobs',
                ],
            ];
        }

        return [
            [
                'key' => 'wholesale-processes',
                'label' => 'Wholesale Processes',
                'href' => route('wiki.wholesale-processes'),
                'current' => request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*'),
            ],
            [
                'key' => 'market-room-process',
                'label' => 'Market Room Guide',
                'href' => route('wiki.article', ['slug' => 'market-room']),
                'current' => request()->routeIs('wiki.article') && request()->route('slug') === 'market-room',
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function quickActions(array $profile, bool $canAccessOps, bool $canAccessMarketing, ?int $tenantId, bool $fieldServiceEnabled = false): array
    {
        $actions = [
            [
                'label' => 'Search everything',
                'description' => 'Open global search and jump straight to customers, orders, features, or actions.',
                'intent' => 'open-command',
            ],
        ];

        if (
            $tenantId !== null
            && ($canAccessOps || $canAccessMarketing)
            && Route::has('onboarding.wizard')
            && config('features.customer_electrician_tutorial', false)
        ) {
            $actions[] = [
                'label' => 'Setup plan',
                'description' => 'Create or continue a workspace setup plan.',
                'href' => route('onboarding.wizard'),
            ];
        }

        if ($canAccessMarketing) {
            $actions[] = [
                'label' => 'Find customer',
                'description' => 'Open the unified customer workspace.',
                'href' => route('marketing.customers'),
            ];
            if ($tenantId !== null) {
                $actions[] = [
                    'label' => 'Browse features',
                    'description' => 'Review active, available, and request-only features.',
                    'href' => route('marketing.modules'),
                ];
            }
        }

        if ($canAccessOps && $fieldServiceEnabled && Route::has('field-service.index')) {
            $actions[] = [
                'label' => 'Create job',
                'description' => 'Add a customer, address, and job in one step.',
                'href' => route('field-service.index'),
            ];
            $actions[] = [
                'label' => 'Add materials',
                'description' => 'Track parts and materials for upcoming work.',
                'href' => route('field-service.index').'#materials',
            ];
            $actions[] = [
                'label' => 'Invite your team',
                'description' => 'Add people who need access to this workspace.',
                'href' => route('admin.index', ['tab' => 'users']),
            ];
        } elseif ($canAccessOps) {
            $actions[] = [
                'label' => 'Review analytics',
                'description' => 'Open operational metrics and drilldowns.',
                'href' => route('analytics.index'),
            ];
        }

        if (($profile['use_case_profile'] ?? 'ops') === 'marketing' && $canAccessMarketing) {
            $actions[] = [
                'label' => 'Open campaigns',
                'description' => 'Go straight to campaign execution and approvals.',
                'href' => route('marketing.campaigns'),
            ];
        }

        return $actions;
    }

    /**
     * @return array<string,mixed>
     */
    protected function opsAttention(?int $tenantId): array
    {
        $unresolvedExceptions = 0;
        $latestRun = null;

        if ($tenantId === null) {
            return [
                'unresolved_exceptions' => 0,
                'latest_run' => null,
            ];
        }

        try {
            if (Schema::hasTable('mapping_exceptions') && Schema::hasColumn('mapping_exceptions', 'tenant_id')) {
                $unresolvedExceptions = MappingException::query()
                    ->forTenantId($tenantId)
                    ->whereNull('resolved_at')
                    ->count();
            }

            if (Schema::hasTable('shopify_import_runs') && Schema::hasColumn('shopify_import_runs', 'tenant_id')) {
                $latestRun = ShopifyImportRun::query()
                    ->forTenantId($tenantId)
                    ->orderByDesc('id')
                    ->first();
            }
        } catch (\Throwable) {
            $unresolvedExceptions = 0;
            $latestRun = null;
        }

        return [
            'unresolved_exceptions' => $unresolvedExceptions,
            'latest_run' => $latestRun,
        ];
    }

    protected function moduleStateRelevant(mixed $state): bool
    {
        if (! is_array($state)) {
            return true;
        }

        return ! in_array((string) ($state['reason'] ?? ''), ['channel_not_supported', 'module_unavailable'], true);
    }

    protected function moduleStateEnabled(mixed $state): bool
    {
        return is_array($state) && (bool) ($state['enabled'] ?? false);
    }

    protected function isFlagshipTenant(?Tenant $tenant): bool
    {
        if (! $tenant instanceof Tenant) {
            return false;
        }

        $slug = strtolower(trim((string) $tenant->slug));
        $name = strtolower(trim((string) $tenant->name));
        $alpha = (array) config('module_catalog.alpha_overrides.ai_assistant', []);

        return in_array($slug, array_map('strtolower', (array) ($alpha['tenant_slugs'] ?? [])), true)
            || in_array($name, array_map('strtolower', (array) ($alpha['tenant_names'] ?? [])), true);
    }

    protected function isFrontYardFoodsTenant(?Tenant $tenant): bool
    {
        return $tenant instanceof Tenant && strtolower(trim((string) $tenant->slug)) === 'front-yard-foods';
    }

    protected function isLandlordShell(Request $request): bool
    {
        return $request->routeIs('landlord.*');
    }
}
