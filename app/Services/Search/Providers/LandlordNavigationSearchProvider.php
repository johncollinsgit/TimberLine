<?php

namespace App\Services\Search\Providers;

use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\LandlordSearchProvider;
use Illuminate\Http\Request;

class LandlordNavigationSearchProvider implements LandlordSearchProvider
{
    use BuildsSearchResults;

    public function __construct(protected UnifiedAppNavigationService $navigationService) {}

    public function search(string $query, array $context = []): array
    {
        $request = $context['request'] ?? request();
        if (! $request instanceof Request || ! $request->routeIs('landlord.*')) {
            return [];
        }

        $normalized = trim($query);
        $navigation = $this->navigationService->build($request, $context['user'] ?? $request->user());
        $items = collect((array) ($navigation['items'] ?? []))
            ->flatMap(function (array $item): array {
                $rows = [$item];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    if (is_array($child)) {
                        $child['parent_label'] = $item['label'] ?? '';
                        $rows[] = $child;
                    }
                }

                return $rows;
            });

        $destinations = $items->map(function (array $item) use ($normalized): array {
            $title = trim((string) ($item['label'] ?? ''));
            $parent = trim((string) ($item['parent_label'] ?? ''));

            return $this->result([
                'type' => 'navigation',
                'subtype' => 'landlord_destination',
                'title' => $title,
                'subtitle' => $parent !== '' ? $parent.' • Everbranch Admin' : 'Everbranch Admin destination',
                'url' => $item['href'] ?? null,
                'badge' => 'Admin page',
                'score' => $this->matchScore($normalized, [$title, $parent], 180),
                'icon' => (string) ($item['icon'] ?? 'rectangle-stack'),
                'meta' => ['control_plane_only' => true],
            ]);
        });

        $actions = collect([
            [
                'title' => 'Create workspace',
                'subtitle' => 'Start a new workspace setup record.',
                'url' => route('landlord.tenants.create'),
                'keywords' => ['new tenant', 'add workspace', 'onboard'],
            ],
            [
                'title' => 'Add a user',
                'subtitle' => 'Choose a workspace, then manage its team access.',
                'url' => route('landlord.tenants.index'),
                'keywords' => ['invite user', 'invite someone', 'new user', 'team member', 'give access'],
            ],
            [
                'title' => 'Review setup queue',
                'subtitle' => 'Open intake and setup readiness.',
                'url' => route('landlord.onboarding.journey'),
                'keywords' => ['setup', 'onboarding', 'review'],
            ],
            [
                'title' => 'See requested Branches',
                'subtitle' => 'Review custom Branch requests from workspaces.',
                'url' => route('landlord.custom-module-requests.index'),
                'keywords' => ['requested branches', 'branch requests', 'module requests', 'requested apps', 'customer request'],
            ],
            [
                'title' => 'Review access requests',
                'subtitle' => 'Open new workspace and access intake.',
                'url' => route('landlord.onboarding.intake'),
                'keywords' => ['access request', 'new lead', 'signup request', 'who wants access'],
            ],
        ])->map(fn (array $action): array => $this->result([
            'type' => 'action',
            'subtype' => 'landlord_action',
            'title' => $action['title'],
            'subtitle' => $action['subtitle'],
            'url' => $action['url'],
            'badge' => 'Action',
            'score' => $this->matchScore($normalized, [$action['title'], $action['subtitle'], ...$action['keywords']], 210),
            'icon' => 'bolt',
            'meta' => ['control_plane_only' => true],
        ]));

        return $destinations
            ->concat($actions)
            ->filter(fn (array $result): bool => $normalized === '' || (int) $result['score'] > 0)
            ->sortByDesc('score')
            ->take(7)
            ->values()
            ->all();
    }
}
