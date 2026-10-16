<?php

namespace App\Services\Search\Providers;

use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Http\Request;

class NavigationSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function __construct(
        protected UnifiedAppNavigationService $navigationService
    ) {}

    public function search(string $query, array $context = []): array
    {
        $request = $context['request'] ?? request();
        if (! $request instanceof Request) {
            $request = request();
        }

        $nav = $this->navigationService->build($request, $context['user'] ?? $request->user());
        $normalized = trim($query);
        $results = [];

        $items = (array) ($nav['items'] ?? []);
        foreach (['admin_sub_items' => 'Settings', 'marketing_sub_groups' => 'Marketing', 'birthday_sub_groups' => 'Birthdays', 'wiki_sections' => 'Help'] as $key => $label) {
            if (! empty($nav[$key])) {
                $items[] = ['label' => $label, 'children' => $nav[$key]];
            }
        }
        $seen = [];
        foreach ($this->searchableNavigationItems($items) as $entry) {
            $title = (string) ($entry['title'] ?? '');
            $url = (string) ($entry['url'] ?? '#');
            if (isset($seen[$url])) {
                continue;
            }
            $icon = (string) ($entry['icon'] ?? 'rectangle-stack');
            $subtitle = (string) ($entry['subtitle'] ?? '');
            $badge = (string) ($entry['badge'] ?? 'Page');
            $subtype = (string) ($entry['subtype'] ?? 'page');
            $meta = is_array($entry['meta'] ?? null) ? (array) $entry['meta'] : [];
            $haystacks = is_array($entry['haystacks'] ?? null) ? (array) $entry['haystacks'] : [$title];
            $scoreBase = is_numeric($entry['score_base'] ?? null) ? (int) $entry['score_base'] : 140;

            $score = $this->matchScore($normalized, $haystacks, $scoreBase);
            $meta['exact_match'] = $normalized !== '' && in_array(mb_strtolower($normalized), array_map('mb_strtolower', $haystacks), true);
            $meta['aliases'] = $haystacks;
            if ($normalized !== '' && $score === 0) {
                continue;
            }

            $seen[$url] = true;

            $results[] = $this->result([
                'type' => 'navigation',
                'subtype' => $subtype,
                'title' => $title !== '' ? $title : 'Page',
                'subtitle' => $subtitle,
                'url' => $url,
                'badge' => $badge,
                'score' => $score ?: 100,
                'icon' => $icon,
                'meta' => $meta,
            ]);
        }

        return $results;
    }

    /**
     * Index the complete authorized tree, including groups without their own page.
     *
     * @param  array<int,mixed>  $items
     * @return array<int,array<string,mixed>>
     */
    protected function searchableNavigationItems(array $items, ?array $parent = null, int $depth = 0): array
    {
        if ($depth > 16) {
            return [];
        }
        $entries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $parentLabel = trim((string) ($item['label'] ?? ''));
            $parentHref = trim((string) ($item['href'] ?? ''));
            if (($item['hidden'] ?? false) || ($item['disabled'] ?? false)) {
                continue;
            }

            $parentIcon = (string) ($item['icon'] ?? $parent['icon'] ?? 'rectangle-stack');
            $parentAliases = $this->aliasesForNavigationItem($item, $parent);

            if ($parentLabel !== '' && $parentHref !== '' && $parentHref !== '#') {
                $entries[] = [
                    'subtype' => $parent === null ? 'page' : 'section',
                    'title' => $parentLabel,
                    'subtitle' => $parent === null ? 'Jump to a primary workspace section.' : ($parent['label'] ?? 'Workspace').' workspace',
                    'url' => $parentHref,
                    'badge' => $parent['label'] ?? 'Page',
                    'icon' => $parentIcon,
                    'haystacks' => array_values(array_filter(array_unique([
                        $parentLabel,
                        ...$parentAliases,
                    ]))),
                    'score_base' => $parent === null ? 140 : 126,
                    'meta' => [
                        'nav_kind' => $parent === null ? 'parent' : 'child',
                        'nav_key' => (string) ($item['key'] ?? ''),
                        'parent_key' => $parent['key'] ?? null,
                        'parent_label' => $parent['label'] ?? null,
                    ],
                ];
            }
            $children = (array) ($item['children'] ?? $item['items'] ?? []);
            $entries = [...$entries, ...$this->searchableNavigationItems($children, [...$item, 'icon' => $parentIcon], $depth + 1)];
        }

        return $entries;
    }

    /**
     * Returns legacy workflow aliases that should match a navigation item.
     * Aliases are used ONLY for matching/scoring; they are never displayed.
     *
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>|null  $parent
     * @return array<int,string>
     */
    protected function aliasesForNavigationItem(array $item, ?array $parent = null): array
    {
        $key = strtolower(trim((string) ($item['key'] ?? '')));
        $parentKey = $parent ? strtolower(trim((string) ($parent['key'] ?? ''))) : null;

        $aliases = array_values(array_filter((array) ($item['aliases'] ?? []), 'is_string'));
        $aliases = [...$aliases, ...match ($key) {
            'home' => ['dashboard', 'overview'],
            'users', 'team' => ['team', 'employees', 'people', 'staff', 'team settings'],
            'settings', 'tenant-settings' => ['settings', 'preferences', 'workspace settings'],
            'field-service', 'field_service', 'work' => ['jobs', 'work', 'projects'],
            'class-scheduling', 'class_scheduling' => ['schedule', 'calendar', 'classes'],
            'branch-mail', 'email' => ['email', 'mail', 'inbox'],
            'branch-website', 'website' => ['website', 'site editor'],
            'fleet-tracking', 'fleet_tracking' => ['fleet', 'vehicles', 'vans', 'crew map'],
            'account-help' => ['help', 'support'],
            default => [],
        }];

        // Parent aliases (keep short + intentional).
        if ($parentKey === null && $key === 'production') {
            $aliases = [...$aliases,
                'operations',
                'ops',
            ];
        }

        // Child aliases under Production.
        if ($parentKey === 'production') {
            $aliases = [...$aliases, ...match ($key) {
                'shipping' => ['shipping room'],
                'pouring' => ['pouring room'],
                'retail-plan' => ['retail plan', 'retail planner', 'retail planning'],
                'markets' => ['market lists', 'market pour list', 'market pour lists'],
                default => [],
            }];
        }

        return array_values(array_filter(array_map(static function (string $alias): string {
            return trim($alias);
        }, $aliases), static fn (string $alias): bool => $alias !== ''));
    }
}
