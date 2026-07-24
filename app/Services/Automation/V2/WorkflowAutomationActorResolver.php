<?php

namespace App\Services\Automation\V2;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Models\User;

class WorkflowAutomationActorResolver
{
    public function resolve(AutomationWorkflow $workflow): User
    {
        $tenant = Tenant::query()->whereKey((int) $workflow->tenant_id)->firstOrFail();
        $candidateIds = array_values(array_filter([
            (int) ($workflow->updated_by_user_id ?? 0),
            (int) ($workflow->created_by_user_id ?? 0),
        ]));

        $actor = $candidateIds === []
            ? null
            : $tenant->users()
                ->wherePivot('membership_active', true)
                ->whereIn('users.id', $candidateIds)
                ->orderByRaw('case when users.id = ? then 0 when users.id = ? then 1 else 2 end', [
                    $candidateIds[0] ?? 0,
                    $candidateIds[1] ?? 0,
                ])
                ->first();

        if ($actor instanceof User) {
            return $actor;
        }

        $actor = $tenant->users()
            ->wherePivot('membership_active', true)
            ->wherePivotIn('role', ['owner', 'tenant_owner', 'admin', 'manager'])
            ->orderBy('users.id')
            ->first();

        if (! $actor instanceof User) {
            throw new \RuntimeException('This workflow needs an active workspace owner or manager before it can change Everbranch records.');
        }

        return $actor;
    }
}
