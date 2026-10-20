<?php

namespace App\Http\Controllers;

use App\Models\TeamUserConnection;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamPreferencesController extends Controller
{
    public function show(Request $request)
    {
        return $this->preferences($request, false);
    }

    public function update(Request $request)
    {
        return $this->preferences($request, true);
    }

    private function preferences(Request $request, bool $write)
    {
        abort_unless(config('team_work.premium_enabled'), 404);
        $tenant = $request->attributes->get('current_tenant');
        $actor = app(TeamWorkAccess::class)->context($request->user(), $tenant);
        $key = ['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'provider' => 'workspace'];
        $record = TeamUserConnection::query()->where($key)->first();
        $state = app(TeamWorkRepository::class)->state($tenant->id);
        $graph = app(WorkGraph::class);
        $preferences = $record?->preferences ?? ['favorites' => [], 'views' => []];
        if ($write) {
            $input = $request->validate(['favorites' => ['sometimes', 'array', 'max:100'], 'favorites.*' => ['uuid', 'distinct'], 'views' => ['sometimes', 'array', 'max:20'], 'views.*' => ['array:id,name,layout,project,completion,sort,date_filter'], 'views.*.id' => ['required', 'uuid', 'distinct'], 'views.*.name' => ['required', 'string', 'max:80'], 'views.*.layout' => ['required', 'in:list,board,calendar,timeline,workload,dashboard'], 'views.*.project' => ['nullable', 'uuid'], 'views.*.completion' => ['required', 'in:all,incomplete,completed'], 'views.*.sort' => ['nullable', 'in:due,start,priority,created,title'], 'views.*.date_filter' => ['required', 'in:all,today,tomorrow,overdue,week,undated']]);
            foreach (array_merge($input['favorites'] ?? [], array_filter(array_column($input['views'] ?? [], 'project'))) as $project) {
                abort_unless($graph->can($state, $actor, 'project', $project), 403);
            }
            $preferences = array_replace($preferences, array_intersect_key($input, array_flip(['favorites', 'views'])));
            $record ??= new TeamUserConnection($key + ['id' => (string) Str::uuid()]);
            $record->preferences = $preferences;
            $record->save();
        }
        $preferences['favorites'] = array_values(array_filter($preferences['favorites'] ?? [], fn ($id) => $graph->can($state, $actor, 'project', $id)));
        $preferences['views'] = array_values(array_filter($preferences['views'] ?? [], fn ($view) => empty($view['project']) || $graph->can($state, $actor, 'project', $view['project'])));

        return response()->json($preferences)->header('Cache-Control', 'private, no-store');
    }
}
