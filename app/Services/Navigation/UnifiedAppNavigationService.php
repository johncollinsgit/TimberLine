<?php

namespace App\Services\Navigation;

use App\Models\MappingException;
use App\Models\ShopifyImportRun;
use App\Models\User;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Birthdays\BirthdaySectionRegistry;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class UnifiedAppNavigationService
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantModuleAccessResolver $moduleAccessResolver
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(Request $request, ?User $user = null): array
    {
        $user ??= $request->user();
        $tenant = $user ? $this->tenantContextResolver->resolveForRequest($request, $user) : null;
        $tenantId = $tenant ? (int) $tenant->id : null;
        $profile = $this->experienceProfileService->forTenant($tenantId, $user, $tenant);

        $isAdmin = $user?->isAdmin() ?? true;
        $isManager = $user?->isManager() ?? false;
        $isPouring = $user?->isPouring() ?? false;
        $canAccessOps = $isAdmin || $isManager;
        $canAccessMarketing = $user?->canAccessMarketing() ?? false;

        $moduleStates = $tenantId !== null
            ? (array) ($this->moduleAccessResolver->resolveForTenant($tenantId, ['birthdays', 'customers', 'campaigns', 'wishlist', 'reporting'])['modules'] ?? [])
            : [];

        $homeHref = $canAccessOps || $canAccessMarketing
            ? route('dashboard')
            : ($isPouring ? route('pouring.index') : route('wiki.index'));

        $items = [];
        $items[] = ['key' => 'home', 'icon' => 'home', 'href' => $homeHref, 'label' => 'Home', 'current' => request()->routeIs('dashboard')];

        if ($canAccessMarketing) {
            $items[] = ['key' => 'marketing', 'icon' => 'megaphone', 'href' => route('marketing.overview'), 'label' => 'Customer Hub', 'current' => request()->routeIs('marketing.*')];

            $birthdaysRelevant = $tenantId === null
                || $this->moduleStateRelevant($moduleStates['birthdays'] ?? null);
            if ($birthdaysRelevant) {
                $items[] = ['key' => 'birthdays', 'icon' => 'gift', 'href' => route('birthdays.customers'), 'label' => 'Birthdays', 'current' => request()->routeIs('birthdays.*')];
            }

            if ($tenantId !== null) {
                $items[] = ['key' => 'modules', 'icon' => 'squares-plus', 'href' => route('marketing.modules'), 'label' => 'Modules', 'current' => request()->routeIs('marketing.modules*')];
            }
        }

        if ($canAccessOps) {
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

            $opsItems = [
                ['key' => 'production', 'icon' => 'beaker', 'href' => route('retail.plan'), 'label' => 'Production', 'current' => $productionCurrent, 'children' => $productionChildren],
                ['key' => 'analytics', 'icon' => 'chart-bar', 'href' => route('analytics.index'), 'label' => 'Analytics', 'current' => request()->routeIs('analytics.*')],
            ];

            $prioritizeGrowth = in_array($profile['use_case_profile'] ?? 'ops', ['marketing', 'crm', 'hybrid'], true);
            $items = $prioritizeGrowth
                ? array_merge($items, $opsItems)
                : array_merge(array_slice($items, 0, 1), $opsItems, array_slice($items, 1));
        } elseif ($isPouring) {
            $items[] = ['key' => 'pouring', 'icon' => 'beaker', 'href' => route('pouring.index'), 'label' => 'Pouring', 'current' => request()->routeIs('pouring.*')];
        }

        if ($canAccessOps) {
            $items[] = ['key' => 'administration', 'icon' => 'wrench-screwdriver', 'href' => route('admin.index'), 'label' => 'Administration', 'current' => request()->routeIs('admin.*')];
        }

        $items[] = ['key' => 'backstage-wiki', 'icon' => 'book-open', 'href' => route('wiki.index'), 'label' => 'Backstage Wiki', 'current' => request()->routeIs('wiki.*')];

        $items = $this->normalizeNavigationItems($items);

        $prefs = is_array($user?->ui_preferences ?? null) ? $user->ui_preferences : [];
        $preferredSidebarOrder = is_array($prefs['sidebar_order'] ?? null) ? $prefs['sidebar_order'] : [];
        $items = $this->orderedItems($items, $preferredSidebarOrder);

        $adminSubItems = $canAccessOps ? $this->adminSubItems($isAdmin) : [];
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
            'wiki_sections' => $this->wikiSections(),
            'quick_actions' => $this->quickActions($profile, $canAccessOps, $canAccessMarketing, $tenantId),
            'ops_attention' => $canAccessOps ? $this->opsAttention() : ['unresolved_exceptions' => 0, 'latest_run' => null],
        ];
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
    protected function adminSubItems(bool $isAdmin): array
    {
        $adminActive = request()->routeIs('admin.*') || request()->is('admin*');
        $adminTab = is_string(request()->query('tab')) ? (string) request()->query('tab') : '';

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
            ], [
                'key' => 'development-notes',
                'label' => 'Development Notes',
                'href' => route('admin.development-notes.index'),
                'current' => request()->routeIs('admin.development-notes.*'),
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
                'label' => 'Scent Catalog',
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
    protected function wikiSections(): array
    {
        return [
            [
                'key' => 'wholesale-processes',
                'label' => 'Wholesale Processes',
                'href' => route('wiki.wholesale-processes'),
                'current' => request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*'),
            ],
            [
                'key' => 'market-room-process',
                'label' => 'Market Room Process',
                'href' => route('wiki.article', ['slug' => 'market-room']),
                'current' => request()->routeIs('wiki.article') && request()->route('slug') === 'market-room',
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function quickActions(array $profile, bool $canAccessOps, bool $canAccessMarketing, ?int $tenantId): array
    {
        $actions = [
            [
                'label' => 'Search everything',
                'description' => 'Open global search and jump straight to customers, orders, modules, or actions.',
                'intent' => 'open-command',
            ],
        ];

        if ($tenantId !== null && ($canAccessOps || $canAccessMarketing) && Route::has('onboarding.wizard')) {
            $actions[] = [
                'label' => 'Onboarding wizard',
                'description' => 'Create or continue an onboarding blueprint (contract + autosave + finalize).',
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
                    'label' => 'Browse modules',
                    'description' => 'Review active, available, and request-only modules.',
                    'href' => route('marketing.modules'),
                ];
            }
        }

        if ($canAccessOps) {
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
    protected function opsAttention(): array
    {
        $unresolvedExceptions = 0;
        $latestRun = null;

        try {
            if (Schema::hasTable('mapping_exceptions')) {
                $unresolvedExceptions = MappingException::query()
                    ->whereNull('resolved_at')
                    ->count();
            }

            if (Schema::hasTable('shopify_import_runs')) {
                $latestRun = ShopifyImportRun::query()
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
}
