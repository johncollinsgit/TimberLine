<?php

namespace App\Http\Controllers;

use App\Models\TeamArea;
use App\Models\Tenant;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Search\SearchDocuments;
use App\Services\Team\Search\SearchIndex;
use App\Services\Team\Search\SearchMatcher;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class TeamSearchController extends Controller
{
    public function __invoke(Request $request, SearchIndex $index, SearchDocuments $documents, SearchMatcher $matcher)
    {
        abort_unless(config('team_work.search_enabled'), 404);
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $actor = app(TeamWorkAccess::class)->context($request->user(), $tenant);
        abort_unless($index->available(), 503, 'Workspace search is being prepared.');
        $input = $request->validate(['q' => 'nullable|string|max:500', 'type' => 'nullable|string|max:32', 'project' => 'nullable|string|max:120',
            'person' => 'nullable|string|max:120', 'state' => 'nullable|in:active,closed', 'cursor' => 'nullable|string|max:4096',
            'limit' => 'nullable|integer|min:1|max:25', 'recent' => 'nullable|array|max:8', 'recent.*' => 'string|max:160', 'ref' => 'nullable|string|max:160']);
        $started = microtime(true);
        $query = $matcher->parse($input['q'] ?? '');
        $limit = (int) ($input['limit'] ?? 8);
        $scope = hash('sha256', json_encode([$tenant->id, $actor['user_id'], $input['q'] ?? '', $input['type'] ?? '', $input['project'] ?? '', $input['person'] ?? '', $input['state'] ?? '', $input['recent'] ?? []]));
        $after = null;
        if (! empty($input['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 32, JSON_THROW_ON_ERROR);
                abort_unless(($cursor['scope'] ?? null) === $scope && is_array($cursor['after'] ?? null), 422);
                $after = $cursor['after'];
            } catch (\Illuminate\Contracts\Encryption\DecryptException|\JsonException $e) {
                abort(422, 'This search page is unavailable. Start the search again.');
            }
        }
        $state = app(TeamWorkRepository::class)->state($tenant->id, false);
        $stateReadAt = microtime(true);
        if (! empty($input['ref'])) {
            [$kind, $id] = array_pad(explode(':', $input['ref'], 2), 2, '');
            $item = $documents->resolve($tenant, $request->user(), $actor, $state, $kind, $id);
            abort_unless($item, 404);

            $display = $this->display($item, '', $matcher);
            if (in_array($item['type'], ['message', 'update'], true)) {
                $display['detail'] = $item['body'];
            }

            return response()->json(['item' => $display])->header('Cache-Control', 'private, no-store');
        }
        $commands = $this->commands($tenant, $request, $actor, $state);
        $items = [];
        $scores = [];
        if (! $query['commandsOnly']) {
            if ($query['text'] === '') {
                foreach ($input['recent'] ?? [] as $ref) {
                    [$kind, $id] = array_pad(explode(':', $ref, 2), 2, '');
                    if ($item = $documents->resolve($tenant, $request->user(), $actor, $state, $kind, $id)) {
                        $items[$item['id']] = $item;
                    }
                }
            } else {
                $fast = [];
                foreach ($index->fastCandidates($tenant->id, $input['q'] ?? '')->cursor() as $candidate) {
                    $indexedRank = [(int) $candidate->bucket, 0, (int) $candidate->sort_closed, -(int) $candidate->sort_updated, $candidate->kind.':'.$candidate->entity_id];
                    if ($after && ($indexedRank <=> $after) <= 0) {
                        continue;
                    }
                    $item = $documents->resolve($tenant, $request->user(), $actor, $state, $candidate->kind, $candidate->entity_id);
                    if (! $item || ! $this->matchesFilters($item, $input)) {
                        continue;
                    }
                    $score = $matcher->score($item, $query);
                    if ($score !== $indexedRank) {
                        // A stale index or changed projection must take the authoritative full path.
                        $fast = [];
                        break;
                    }
                    $fast[$item['id']] = $item;
                    $scores[$item['id']] = $score;
                    if (count($fast) > $limit) {
                        break;
                    }
                }
                $completeTopPage = count($fast) > $limit;
                $items = $fast;
                $candidates = $completeTopPage ? [] : $index->candidates($tenant->id, $input['q'] ?? '')->select(['id', 'kind', 'entity_id'])->orderBy('id')->cursor();
                foreach ($candidates as $candidate) {
                    $item = $documents->resolve($tenant, $request->user(), $actor, $state, $candidate->kind, $candidate->entity_id);
                    if ($item && ($score = $matcher->score($item, $query)) !== null) {
                        $items[$item['id']] = $item;
                        $scores[$item['id']] = $score;
                    }
                }
                // Directory membership and names are always current, including new joins and renames.
                $members = in_array($actor['role'], ['guest', 'viewer'], true) ? collect([$request->user()])
                    : $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->get();
                foreach ($members as $member) {
                    $item = $documents->person($member);
                    if ($matcher->score($item, $query) !== null) {
                        $items[$item['id']] = $item;
                        $scores[$item['id']] = $matcher->score($item, $query);
                    }
                }
            }
        }
        foreach ($commands as $command) {
            if ($matcher->score($command, $query) !== null) {
                $items[$command['id']] = $command;
            }
        }
        $matchedAt = microtime(true);
        $facets = ['type' => [], 'project' => [], 'person' => []];
        foreach ($items as $item) {
            $facets['type'][$item['type']] = ['id' => $item['type'], 'title' => ucfirst($item['type'])];
            foreach ($item['projects'] ?? [] as $id) {
                $name = $state['projects'][$id]['name'] ?? $item['context'] ?? $id;
                $facets['project'][$id] = ['id' => $id, 'title' => $name];
            }
            foreach ($item['people'] ?? [] as $id) {
                $facets['person'][$id] = ['id' => (string) $id, 'title' => 'Member '.$id];
            }
        }
        $members = in_array($actor['role'], ['guest', 'viewer'], true) ? collect([$request->user()])
            : $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->get();
        foreach ($members as $member) {
            $facets['person'][$member->id] = ['id' => (string) $member->id, 'title' => $member->name];
        }
        // Filter choices describe the permitted workspace, never hidden-result counts.
        foreach ($state['projects'] as $id => $project) {
            if (app(WorkGraph::class)->can($state, $actor, 'project', $id)) {
                $facets['project'][$id] = ['id' => $id, 'title' => $project['name']];
            }
        }
        foreach (['task', 'project', 'update', 'file', 'person', 'command'] as $type) {
            $facets['type'][$type] = ['id' => $type, 'title' => ucfirst($type)];
        }
        $items = array_values(array_filter($items, fn ($item) => $this->matchesFilters($item, $input)));
        $ranks = [];
        foreach ($items as $item) {
            $ranks[$item['id']] = $scores[$item['id']] ?? $matcher->score($item, $query);
            if ($query['text'] === '' && ! $query['commandsOnly']) {
                $recentPosition = array_search($item['id'], $input['recent'] ?? [], true);
                $ranks[$item['id']] = [$item['type'] === 'command' ? 1 : 0, $recentPosition === false ? 99 : $recentPosition, 0, 0, $item['id']];
            }
        }
        usort($items, fn ($a, $b) => $ranks[$a['id']] <=> $ranks[$b['id']]);
        if ($after !== null) {
            $items = array_values(array_filter($items, fn ($item) => ($ranks[$item['id']] <=> $after) > 0));
        }
        $more = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $cursor = $more ? Crypt::encryptString(json_encode(['scope' => $scope, 'after' => $ranks[$items[array_key_last($items)]['id']]], JSON_THROW_ON_ERROR)) : null;
        Log::info('team.search', ['duration_ms' => round((microtime(true) - $started) * 1000), 'state_read_ms' => round(($stateReadAt - $started) * 1000),
            'matching_ms' => round(($matchedAt - $stateReadAt) * 1000), 'presentation_ms' => round((microtime(true) - $matchedAt) * 1000), 'result_count' => count($items), 'has_more' => $more]);

        return response()->json(['items' => array_map(fn ($item) => $this->display($item, $input['q'] ?? '', $matcher), $items),
            'next_cursor' => $cursor, 'facets' => array_map('array_values', $facets), 'schema_version' => SearchMatcher::VERSION])
            ->header('Cache-Control', 'private, no-store');
    }

    private function display(array $item, string $query, SearchMatcher $matcher): array
    {
        $item['snippet'] = $matcher->snippet($item['body'] ?? '', $query);
        unset($item['body']);

        return $item;
    }

    private function matchesFilters(array $item, array $input): bool
    {
        return (empty($input['type']) || $item['type'] === $input['type'])
            && (empty($input['project']) || in_array($input['project'], $item['projects'] ?? [], true))
            && (empty($input['person']) || in_array($input['person'], $item['people'] ?? [], true))
            && (($input['state'] ?? '') !== 'active' || (empty($item['completed']) && empty($item['archived'])))
            && (($input['state'] ?? '') !== 'closed' || ! empty($item['completed']) || ! empty($item['archived']));
    }

    private function commands(Tenant $tenant, Request $request, array $actor, array $state): array
    {
        $make = fn ($id, $title, $target) => ['id' => 'command:'.$id, 'type' => 'command', 'title' => $title, 'body' => '', 'source' => 'Everbranch', 'target' => $target];
        $catalog = json_decode(file_get_contents(resource_path('data/team-search-commands.json')), true, flags: JSON_THROW_ON_ERROR);
        $items = [];
        foreach (['home' => 'Go to Home', 'mine' => 'Go to My tasks', 'all' => 'Go to All work', 'inbox' => 'Go to Inbox'] as $id => $title) {
            $items[] = $make($id, $title, ['kind' => 'navigate', 'id' => $id]);
        }
        $creator = in_array($actor['role'], ['owner', 'admin', 'member'], true);
        $canTask = $creator || collect($state['projects'])->contains(fn ($p) => app(WorkGraph::class)->can($state, $actor, 'project', $p['id'], true));
        if ($canTask) {
            $items[] = $make('create-task', $catalog['create-task']['title'], ['kind' => $catalog['create-task']['kind']]);
        }
        if ($creator) {
            $items[] = $make('create-project', $catalog['create-project']['title'], ['kind' => $catalog['create-project']['kind']]);
        }
        if (config('team_operations.enabled') && config('team_work.premium_enabled')) {
            foreach (TeamArea::where('tenant_id', $tenant->id)->get() as $area) {
                try {
                    app(OperationsAccess::class)->area($tenant, $request->user(), $area->id);
                    $items[] = $make('area-'.$area->id, 'Go to '.$area->name, ['kind' => 'area', 'area' => $area->id]);
                    app(OperationsAccess::class)->area($tenant, $request->user(), $area->id, true);
                    $items[] = $make('create-operation-'.$area->id, $catalog['create-operation']['title'].' in '.$area->name, ['kind' => $catalog['create-operation']['kind'], 'area' => $area->id]);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    if (! in_array($e->getStatusCode(), [403, 404], true)) {
                        throw $e;
                    }
                }
            }
        }

        return $items;
    }
}
