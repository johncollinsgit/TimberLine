<?php

namespace App\Policies;

use App\Models\TeamTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;

class TeamTaskPolicy
{
    private function allows(User $user, TeamTask $task, bool $write): bool
    {
        $tenant = Tenant::query()->findOrFail($task->tenant_id);
        $context = app(TeamWorkAccess::class)->context($user, $tenant);

        return app(WorkGraph::class)->can(app(TeamWorkRepository::class)->state($tenant->id), $context, 'task', $task->id, $write);
    }

    public function view(User $user, TeamTask $task): bool
    {
        return $this->allows($user, $task, false);
    }

    public function update(User $user, TeamTask $task): bool
    {
        return $this->allows($user, $task, true);
    }

    public function replayDeletion(User $user, TeamTask $task): bool
    {
        $tenant = Tenant::query()->findOrFail($task->tenant_id);
        $actor = app(TeamWorkAccess::class)->context($user, $tenant);
        $state = app(TeamWorkRepository::class)->state($tenant->id);
        if (! isset($state['tasks'][$task->id])) {
            return false;
        }
        $state['tasks'][$task->id]['deleted'] = false;

        return app(WorkGraph::class)->can($state, $actor, 'task', $task->id);
    }
}
