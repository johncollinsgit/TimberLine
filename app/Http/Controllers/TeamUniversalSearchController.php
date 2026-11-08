<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserRole;
use App\Models\Tenant;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Team\Search\SearchDocuments;
use App\Services\Team\Search\SearchIndex;
use App\Services\Team\Search\SearchMatcher;
use App\Services\Team\TeamWorkAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A read-only adapter over the existing Teams and app search authorities. */
class TeamUniversalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchCoordinator $apps, TeamSearchController $teams, SearchIndex $index, SearchDocuments $documents, SearchMatcher $matcher)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $actor = app(TeamWorkAccess::class)->context($request->user(), $tenant);
        $input = $request->validate(['q' => 'nullable|string|max:500', 'type' => 'nullable|string|max:32', 'project' => 'nullable|string|max:120',
            'person' => 'nullable|string|max:120', 'state' => 'nullable|in:active,closed', 'cursor' => 'nullable|string|max:10000',
            'limit' => 'nullable|integer|min:1|max:25', 'recent' => 'nullable|array|max:8', 'recent.*' => 'string|max:160', 'ref' => 'nullable|string|max:160']);
        $scope = hash('sha256', json_encode([$tenant->id, $actor['user_id'], array_diff_key($input, array_flip(['cursor', 'limit', 'ref']))]));
        $cursor = ['seen' => [], 'team' => null, 'done' => false];
        if (! empty($input['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 32, JSON_THROW_ON_ERROR);
                abort_unless(($cursor['scope'] ?? '') === $scope && is_array($cursor['seen'] ?? null) && is_bool($cursor['done'] ?? null), 422);
            } catch (\Illuminate\Contracts\Encryption\DecryptException|\JsonException) {
                abort(422, 'Start this search again.');
            }
        }
        $appRows = [];
        // Project/person/state filters describe Teams work, not other applications.
        $needsApps = empty($input['ref']) || str_starts_with($input['ref'], 'app:');
        if ($needsApps && empty($input['project']) && empty($input['person']) && empty($input['state']) && ! str_starts_with(trim($input['q'] ?? ''), '>')) {
            $payload = $apps->search(mb_substr($input['q'] ?? '', 0, 120), ['tenant_id' => $tenant->id, 'user' => $request->user(), 'request' => $request, 'surface' => 'teams', 'limit' => 20]);
            foreach ($payload['results'] as $row) {
                if ($url = $this->destination($request, $tenant, $row['url'] ?? null)) {
                    $id = $this->appIdentity($row, $url);
                    if (empty($input['type']) || $input['type'] === ($row['type'] ?? '')) {
                        $appRows[$id] = ['id' => $id, 'type' => $row['type'], 'title' => $row['title'], 'context' => $row['badge'], 'snippet' => $row['subtitle'], 'source' => 'Everbranch', 'target' => ['kind' => 'app', 'url' => $url]];
                    }
                }
            }
        }
        if (str_starts_with($input['ref'] ?? '', 'app:')) {
            abort_unless(isset($appRows[$input['ref']]), 404);

            return response()->json(['item' => $appRows[$input['ref']]])->header('Cache-Control', 'private, no-store');
        }
        $pendingApps = array_diff_key($appRows, array_flip($cursor['seen']));
        $limit = (int) ($input['limit'] ?? 8);
        $teamInput = array_diff_key($input, ['cursor' => true]);
        $teamInput['recent'] = array_values(array_filter($input['recent'] ?? [], fn ($id) => ! str_starts_with($id, 'app:')));
        $teamInput['limit'] = $pendingApps ? max(1, (int) ceil($limit / 2)) : $limit;
        $teamInput['cursor'] = $cursor['team'];
        $teamRequest = $request->duplicate($teamInput);
        $team = $cursor['done'] && empty($input['ref']) ? ['items' => [], 'next_cursor' => null, 'facets' => []]
            : $teams->workspace($teamRequest, $index, $documents, $matcher)->getData(true);
        if (! empty($input['ref'])) {
            return response()->json($team)->header('Cache-Control', 'private, no-store');
        }
        $items = $team['items'];
        $appPage = array_slice($pendingApps, 0, max(0, $limit - count($items)), true);
        foreach ($appPage as $id => $row) {
            $items[] = $row;
            $cursor['seen'][] = $id;
        }
        $cursor = [...$cursor, 'scope' => $scope, 'team' => $team['next_cursor'], 'done' => ! $team['next_cursor']];
        $cursor['coverage'] = $team['coverage'] ?? $cursor['coverage'] ?? ['complete' => true, 'basis' => 'saved_workspace'];
        $more = $team['next_cursor'] || count($pendingApps) > count($appPage);
        $facets = $team['facets'];
        $types = collect($facets['type'] ?? [])->keyBy('id');
        foreach ($appRows as $row) {
            $types[$row['type']] = ['id' => $row['type'], 'title' => ucfirst($row['type'])];
        }
        $facets['type'] = $types->values()->all();

        return response()->json(['items' => $items, 'next_cursor' => $more ? Crypt::encryptString(json_encode($cursor)) : null, 'facets' => $facets,
            'coverage' => $cursor['coverage'], 'app_limit' => 20])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Stable provider-scoped record identity; a shared destination is not a record ID. */
    private function appIdentity(array $row, string $url): string
    {
        $key = match ($row['subtype'] ?? '') {
            'storefront', 'integration_health' => 'event_id',
            'marketing_run', 'shopify_run' => 'run_id',
            'profile' => 'profile_id',
            'record' => 'order_id',
            'field_service_job' => 'job_id',
            'quickbooks_document' => 'document_id',
            'workspace_asset' => 'asset_id',
            'catalog' => 'module_key',
            default => null,
        };
        $identity = $key ? data_get($row, 'meta.'.$key) : null;
        if (! is_string($identity) && ! is_int($identity)) {
            $identity = null;
        }

        return 'app:'.hash('sha256', json_encode([$row['type'] ?? '', $row['subtype'] ?? '', $key, $identity, $url], JSON_THROW_ON_ERROR));
    }

    /** Reuse destination role/module middleware without invoking a page or provider. */
    private function destination(Request $request, Tenant $tenant, mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '//')) {
            return null;
        }
        $parts = parse_url($value);
        if (! $parts || (isset($parts['host']) && $parts['host'] !== $request->getHost()) || (isset($parts['scheme']) && ! in_array($parts['scheme'], ['http', 'https'], true))) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $query['tenant'] = (string) $tenant->id;
        $url = ($parts['path'] ?? '/').'?'.http_build_query($query);
        $probe = Request::create($request->getSchemeAndHttpHost().$url, 'GET');
        $probe->setUserResolver($request->getUserResolver());
        $probe->attributes->set('current_tenant', $tenant);
        $probe->attributes->set('current_tenant_id', $tenant->id);
        if ($request->hasSession()) {
            $probe->setLaravelSession($request->session());
        }
        try {
            $route = app('router')->getRoutes()->match($probe);
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'role:')) {
                    app(EnsureUserRole::class)->handle($probe, fn () => response(''), ...explode(',', substr($middleware, 5)));
                } elseif (str_starts_with($middleware, 'module:')) {
                    app(EnsureModuleAccess::class)->handle($probe, fn () => response(''), substr($middleware, 7));
                } elseif ($middleware === 'landlord.operator' || str_starts_with($middleware, 'shopify.')) {
                    return null;
                }
            }
        } catch (HttpException|\Symfony\Component\Routing\Exception\ResourceNotFoundException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return null;
        }

        return $url;
    }
}
