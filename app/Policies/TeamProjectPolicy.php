<?php

namespace App\Policies;

use App\Models\TeamProject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;

class TeamProjectPolicy
{
    public function view(User $user, TeamProject $project): bool
    {
        $tenant = Tenant::query()->findOrFail($project->tenant_id);
        $context = app(TeamWorkAccess::class)->context($user, $tenant);

        return app(WorkGraph::class)->can(app(TeamWorkRepository::class)->state($tenant->id), $context, 'project', $project->id);
    }

    public function update(User $user, TeamProject $project): bool
    {
        $tenant = Tenant::query()->findOrFail($project->tenant_id);
        $actor = app(TeamWorkAccess::class)->context($user, $tenant);

        return in_array($actor['role'], ['owner', 'admin', 'member'], true)
            && (string) $project->owner_user_id === (string) $user->id
            && app(WorkGraph::class)->can(app(TeamWorkRepository::class)->state($tenant->id), $actor, 'project', $project->id);
    }
}
