<?php

namespace App\Services\Shopify;

use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;

class ShopifyEmbeddedShellPayloadBuilder
{
    protected const REQUEST_CACHE_KEY = '_shopify_embedded_shell_payload_cache';

    public function __construct(
        protected ShopifyEmbeddedPageRegistry $pageRegistry,
        protected ShopifyEmbeddedUrlGenerator $urlGenerator,
        protected TenantDisplayLabelResolver $displayLabelResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantModuleAccessResolver $moduleAccessResolver
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function appNavigation(
        string $activeSection,
        ?string $activeChild = null,
        ?int $tenantId = null,
        ?Request $request = null
    ): array {
        $request ??= request();
        $displayLabels = $this->displayLabels($tenantId, $request);
        $moduleStates = $this->moduleStates($tenantId, $request);
        $profile = $this->experienceProfile($tenantId, $request);

        $items = array_map(function (array $page) use ($request, $displayLabels, $moduleStates): array {
            $item = [
                'key' => (string) ($page['section'] ?? $page['key'] ?? ''),
                'label' => $this->resolvedLabel($page, $displayLabels),
                'href' => $this->urlGenerator->route((string) ($page['route_name'] ?? ''), [], false),
                'children' => [],
                'prefetch_priority' => (string) ($page['prefetch_priority'] ?? 'normal'),
            ];

            $moduleKey = strtolower(trim((string) ($page['module_key'] ?? '')));
            if ($moduleKey !== '' && is_array($moduleStates[$moduleKey] ?? null)) {
                $item['module_state'] = $moduleStates[$moduleKey];
            }

            if ((string) ($page['section'] ?? '') !== 'rewards') {
                return $item;
            }

            $children = [];
            foreach ($this->pageRegistry->pagesForGroup('rewards_children') as $childPage) {
                $childKey = $this->childKeyFromPage($childPage['key'] ?? '');
                $child = [
                    'key' => $childKey,
                    'label' => $this->resolvedLabel($childPage, $displayLabels),
                    'href' => $this->urlGenerator->route((string) ($childPage['route_name'] ?? ''), [], false),
                    'prefetch_priority' => (string) ($childPage['prefetch_priority'] ?? 'normal'),
                ];

                $childModuleKey = strtolower(trim((string) ($childPage['module_key'] ?? '')));
                if ($childModuleKey !== '' && is_array($moduleStates[$childModuleKey] ?? null)) {
                    $child['module_state'] = $moduleStates[$childModuleKey];
                }

                $children[] = $child;
            }

            $item['children'] = $children;

            return $item;
        }, $this->pageRegistry->pagesForGroup('primary'));

        return [
            'items' => $items,
            'activeSection' => $activeSection,
            'activeChild' => $activeChild,
            'moduleStates' => $moduleStates,
            'tenantId' => $tenantId,
            'displayLabels' => $displayLabels,
            'workspaceLabel' => (string) data_get($profile, 'workspace.label', 'Commerce'),
            'commandSearchEndpoint' => $this->urlGenerator->route('shopify.app.api.search'),
            'commandSearchPlaceholder' => (string) data_get($profile, 'workspace.command_placeholder', 'Search actions, pages, and Shopify tools'),
            'commandSearchDocuments' => $this->commandSearchDocuments(
                tenantId: $tenantId,
                request: $request,
                activeSection: $activeSection,
                activeChild: $activeChild
            ),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function customerSubnav(string $activeKey, ?int $tenantId = null, ?Request $request = null): array
    {
        $request ??= request();
        $moduleStates = $this->moduleStates($tenantId, $request);
        $displayLabels = $this->displayLabels($tenantId, $request);

        return array_map(function (array $page) use ($activeKey, $moduleStates, $displayLabels): array {
            $shortKey = $this->childKeyFromPage((string) ($page['key'] ?? ''));
            $moduleKey = strtolower(trim((string) ($page['module_key'] ?? '')));

            return [
                'key' => $shortKey,
                'label' => $this->resolvedLabel($page, $displayLabels),
                'href' => $this->urlGenerator->route((string) ($page['route_name'] ?? ''), [], false),
                'active' => $shortKey === $activeKey,
                'module_state' => $moduleKey !== '' && is_array($moduleStates[$moduleKey] ?? null)
                    ? $moduleStates[$moduleKey]
                    : null,
                'prefetch_priority' => (string) ($page['prefetch_priority'] ?? 'normal'),
            ];
        }, $this->pageRegistry->pagesForGroup('customers_subnav'));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function dashboardSubnav(string $activeKey, ?int $tenantId = null, ?Request $request = null): array
    {
        $request ??= request();
        $moduleStates = $this->moduleStates($tenantId, $request);
        $displayLabels = $this->displayLabels($tenantId, $request);

        $pages = array_merge(
            [$this->pageRegistry->pageByKey('home') ?: []],
            $this->pageRegistry->pagesForGroup('dashboard_subnav')
        );

        return array_values(array_filter(array_map(function (array $page) use ($activeKey, $moduleStates, $displayLabels): ?array {
            if ($page === []) {
                return null;
            }

            $shortKey = (string) ($page['key'] ?? '') === 'home'
                ? 'overview'
                : $this->childKeyFromPage((string) ($page['key'] ?? ''));
            $moduleKey = strtolower(trim((string) ($page['module_key'] ?? '')));

            return [
                'key' => $shortKey,
                'label' => (string) ($shortKey === 'overview' ? 'Overview' : $this->resolvedLabel($page, $displayLabels)),
                'href' => $this->urlGenerator->route((string) ($page['route_name'] ?? ''), [], false),
                'active' => $shortKey === $activeKey,
                'module_state' => $moduleKey !== '' && is_array($moduleStates[$moduleKey] ?? null)
                    ? $moduleStates[$moduleKey]
                    : null,
                'prefetch_priority' => (string) ($page['prefetch_priority'] ?? 'normal'),
            ];
        }, $pages)));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function embeddedSearchResults(string $query, ?int $tenantId = null, ?Request $request = null): array
    {
        $request ??= request();
        $displayLabels = $this->displayLabels($tenantId, $request);
        $moduleStates = $this->moduleStates($tenantId, $request);
        $normalizedQuery = strtolower(trim($query));

        $entries = collect($this->pageRegistry->pages())
            ->filter(fn (array $page): bool => (bool) ($page['searchable'] ?? false))
            ->filter(fn (array $page): bool => $this->searchVisibleForModuleState($page, $moduleStates))
            ->map(function (array $page) use ($displayLabels, $normalizedQuery): ?array {
                $title = $this->resolvedLabel($page, $displayLabels);
                $subtitle = (string) ($page['search_subtitle'] ?? 'Jump to a workspace section.');
                $keywords = array_map('strval', (array) ($page['search_keywords'] ?? []));

                $score = $this->searchScore($normalizedQuery, array_merge([$title, $subtitle], $keywords));
                if ($normalizedQuery !== '' && $score === 0) {
                    return null;
                }

                return [
                    'type' => 'Backstage',
                    'subtype' => 'section',
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'url' => $this->urlGenerator->route((string) ($page['route_name'] ?? '')),
                    'badge' => (string) ($page['search_badge'] ?? 'Section'),
                    'score' => $score ?: 260,
                    'icon' => (string) ($page['icon_key'] ?? 'rectangle-stack'),
                    'meta' => [],
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $entry): int => (int) ($entry['score'] ?? 0))
            ->values()
            ->all();

        return is_array($entries) ? $entries : [];
    }

    /**
     * @return array<string,string>
     */
    public function displayLabels(?int $tenantId, ?Request $request = null): array
    {
        $request ??= request();

        return $this->remember($request, 'display_labels:tenant:'.$tenantId, function () use ($tenantId): array {
            $resolved = $this->displayLabelResolver->resolve($tenantId);

            return is_array($resolved['labels'] ?? null) ? (array) $resolved['labels'] : [];
        });
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function moduleStates(?int $tenantId, ?Request $request = null): array
    {
        $request ??= request();

        return $this->remember($request, 'module_states:tenant:'.$tenantId, function () use ($tenantId): array {
            $moduleKeys = collect($this->pageRegistry->pages())
                ->pluck('module_key')
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->map(fn (string $value): string => strtolower(trim($value)))
                ->unique()
                ->values()
                ->all();

            $resolved = $this->moduleAccessResolver->resolveForTenant($tenantId, $moduleKeys);

            return is_array($resolved['modules'] ?? null) ? (array) $resolved['modules'] : [];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function experienceProfile(?int $tenantId, ?Request $request = null): array
    {
        $request ??= request();
        $user = $request->user();

        return $this->remember($request, 'experience_profile:tenant:'.$tenantId.':user:'.($user?->id ?? 0), function () use ($tenantId, $user): array {
            return $this->experienceProfileService->forTenant($tenantId, $user);
        });
    }

    protected function resolvedLabel(array $page, array $displayLabels): string
    {
        $labelKey = strtolower(trim((string) ($page['label_key'] ?? '')));
        if ($labelKey !== '') {
            $label = trim((string) ($displayLabels[$labelKey] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return trim((string) ($page['label'] ?? ''));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function commandSearchDocuments(
        ?int $tenantId,
        Request $request,
        string $activeSection,
        ?string $activeChild = null
    ): array {
        $displayLabels = $this->displayLabels($tenantId, $request);
        $moduleStates = $this->moduleStates($tenantId, $request);
        $currentRoute = strtolower(trim((string) ($request->route()?->getName() ?? '')));
        $activeSection = strtolower(trim($activeSection));
        $activeChild = strtolower(trim((string) $activeChild));

        return collect($this->pageRegistry->pages())
            ->filter(fn (array $page): bool => $this->searchVisibleForModuleState($page, $moduleStates))
            ->map(function (array $page) use ($displayLabels, $currentRoute, $activeSection, $activeChild): ?array {
                $routeName = strtolower(trim((string) ($page['route_name'] ?? '')));
                if ($routeName === '') {
                    return null;
                }

                try {
                    $url = trim((string) $this->urlGenerator->route($routeName, [], false));
                } catch (UrlGenerationException) {
                    return null;
                }

                if ($url === '' || str_contains($url, '{')) {
                    return null;
                }

                $pageKey = strtolower(trim((string) ($page['key'] ?? '')));
                $pageSection = strtolower(trim((string) ($page['section'] ?? '')));
                $pageChild = strtolower(trim((string) $this->childKeyFromPage((string) ($page['key'] ?? ''))));
                $isCurrentView = $currentRoute !== ''
                    && ($routeName === $currentRoute || in_array($currentRoute, array_map('strtolower', (array) ($page['legacy_route_names'] ?? [])), true));
                if (! $isCurrentView && $pageSection !== '' && $pageSection === $activeSection) {
                    $isCurrentView = $activeChild === '' || $pageChild === $activeChild;
                }

                $label = $this->resolvedLabel($page, $displayLabels);
                if ($label === '') {
                    return null;
                }

                $subtitle = trim((string) ($page['search_subtitle'] ?? 'Open this workspace page.'));
                $keywords = array_values(array_filter(array_map(
                    static fn ($keyword): string => trim((string) $keyword),
                    array_merge(
                        [$label, $pageKey, $pageSection, $pageChild, (string) ($page['label_key'] ?? '')],
                        (array) ($page['search_keywords'] ?? []),
                        (array) ($page['legacy_route_names'] ?? [])
                    )
                ), static fn (string $keyword): bool => $keyword !== ''));
                $breadcrumbs = array_values(array_filter([
                    $pageSection !== '' ? str_replace(['_', '-'], ' ', ucfirst($pageSection)) : null,
                    in_array((string) ($page['group'] ?? ''), ['customers_subnav', 'dashboard_subnav', 'rewards_children'], true)
                        ? 'Section'
                        : null,
                ]));

                return [
                    'id' => 'page:'.$pageKey,
                    'title' => $label,
                    'subtitle' => $subtitle,
                    'section' => $isCurrentView ? 'current-view' : 'pages',
                    'keywords' => $keywords,
                    'breadcrumbs' => $breadcrumbs,
                    'aliases' => array_values(array_filter((array) ($page['legacy_route_names'] ?? []))),
                    'entityType' => 'page',
                    'execute' => [
                        'type' => 'navigate',
                        'url' => $url,
                    ],
                ];
            })
            ->filter()
            ->unique(fn (array $row): string => strtolower(trim((string) ($row['id'] ?? '')).':'.trim((string) data_get($row, 'execute.url', ''))))
            ->values()
            ->all();
    }

    protected function childKeyFromPage(string $pageKey): string
    {
        $normalized = trim($pageKey);
        if ($normalized === '') {
            return '';
        }

        if (! str_contains($normalized, '.')) {
            return $normalized;
        }

        $parts = explode('.', $normalized);

        return (string) end($parts);
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     */
    protected function searchVisibleForModuleState(array $page, array $moduleStates): bool
    {
        $moduleKey = strtolower(trim((string) ($page['module_key'] ?? '')));
        if ($moduleKey === '') {
            return true;
        }

        $state = is_array($moduleStates[$moduleKey] ?? null) ? $moduleStates[$moduleKey] : null;
        if (! is_array($state)) {
            return true;
        }

        $reason = strtolower(trim((string) ($state['reason'] ?? '')));

        return ! in_array($reason, ['channel_not_supported', 'module_unavailable'], true);
    }

    protected function searchScore(string $query, array $haystacks, int $base = 260): int
    {
        if ($query === '') {
            return $base;
        }

        foreach ($haystacks as $index => $haystack) {
            $normalized = strtolower(trim((string) $haystack));
            if ($normalized === '') {
                continue;
            }

            if ($normalized === $query) {
                return $base + 120 - ($index * 5);
            }

            if (str_starts_with($normalized, $query)) {
                return $base + 80 - ($index * 5);
            }

            if (str_contains($normalized, $query)) {
                return $base + 40 - ($index * 5);
            }
        }

        return 0;
    }

    /**
     * @template T
     *
     * @param  callable():T  $resolver
     * @return T
     */
    protected function remember(Request $request, string $key, callable $resolver)
    {
        $cache = $request->attributes->get(self::REQUEST_CACHE_KEY, []);
        if (is_array($cache) && array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $value = $resolver();
        if (! is_array($cache)) {
            $cache = [];
        }
        $cache[$key] = $value;
        $request->attributes->set(self::REQUEST_CACHE_KEY, $cache);

        return $value;
    }
}
