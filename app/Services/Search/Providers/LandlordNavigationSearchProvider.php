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
        $destinations = collect((new NavigationSearchProvider($this->navigationService))->search($query, $context))
            ->map(fn (array $row): array => [...$row, 'subtype' => 'landlord_destination', 'badge' => 'Admin page',
                'meta' => [...$row['meta'], 'control_plane_only' => true]]);

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
            ->filter(fn (array $row): bool => empty($context['navigation_only']) || app(\App\Services\Navigation\NavigationQuery::class)->isDestination($row))
            ->filter(fn (array $result): bool => $normalized === '' || (int) $result['score'] > 0)
            ->sortByDesc('score')
            ->take(7)
            ->values()
            ->all();
    }
}
