<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkAccess;
use Illuminate\Support\Facades\DB;

class WorkspaceAccess
{
    public const PROFILES = ['enterprise_shared', 'enterprise_department'];

    public function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.runtime') && config('enterprise.enabled')
            && in_array((string) $tenant->id, array_map('strval', config('enterprise.tenant_allowlist', [])), true), 404);
        abort_if($write && ! config('enterprise.writes_enabled'), 403, 'Enterprise changes are paused.');

        return app(TeamWorkAccess::class)->context($user->fresh() ?? $user, $tenant);
    }

    /** Trusted scope for graph access, including after rollout is disabled. */
    public function graphContext(Tenant $tenant): array
    {
        $areas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', self::PROFILES)->get();
        $members = DB::table('team_area_members')->where('tenant_id', $tenant->id)->whereIn('area_id', $areas->pluck('id'))->get();
        $scopes = [];
        foreach ($areas as $area) {
            $scopes[$area->id] = ['shared' => $area->profile === 'enterprise_shared', 'members' => []];
        }
        foreach ($members as $member) {
            $scopes[$member->area_id]['members'][(string) $member->user_id] = $member->role;
        }

        return ['enterprise_scopes' => $scopes,
            'enterprise_available' => (bool) config('enterprise.runtime') && (bool) config('enterprise.enabled')
                && in_array((string) $tenant->id, array_map('strval', config('enterprise.tenant_allowlist', [])), true),
            'enterprise_writes' => (bool) config('enterprise.writes_enabled')];
    }

    public static function permits(array $actor, string $areaId, bool $write = false, bool $coordinate = false): bool
    {
        $scope = $actor['enterprise_scopes'][$areaId] ?? null;
        if (! $scope || ! ($actor['enterprise_available'] ?? false) || ($write && ! ($actor['enterprise_writes'] ?? false))) {
            return false;
        }
        if (in_array($actor['role'], ['owner', 'admin'], true)) {
            return true;
        }
        if (in_array($actor['role'], ['guest'], true) || ($write && $actor['role'] === 'viewer')) {
            return false;
        }
        $role = $scope['members'][$actor['user_id']] ?? null;
        if ($coordinate) {
            return $role === 'coordinator';
        }

        return $write ? in_array($role, ['editor', 'coordinator'], true) : ($scope['shared'] || in_array($role, ['viewer', 'editor', 'coordinator'], true));
    }

    public function area(Tenant $tenant, array $actor, string $id, bool $coordinate = false): TeamArea
    {
        $area = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', self::PROFILES)->findOrFail($id);
        abort_unless(self::permits($actor, $id, $coordinate, $coordinate), 404);

        return $area;
    }

    public static function canSubmit(array $actor, string $areaId): bool
    {
        if (! self::permits($actor, $areaId) || ! ($actor['enterprise_writes'] ?? false)
            || ! in_array($actor['role'], ['owner', 'admin', 'member'], true)) {
            return false;
        }
        $scope = $actor['enterprise_scopes'][$areaId];

        return self::permits($actor, $areaId, true) || ($scope['shared'] && ! isset($scope['members'][$actor['user_id']]));
    }
}
