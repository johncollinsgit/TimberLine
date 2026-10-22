<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** Display defaults and published intake are explicit settings, never permission or billing mutations. */
class WorkspacePreferences
{
    public function __construct(private WorkspaceAccess $access, private RecordStore $store) {}

    private function authorize(Tenant $tenant, User $user, string $areaId, bool $write = false): array
    {
        abort_unless(config('enterprise.operations_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $write);
        $this->access->area($tenant, $actor, $areaId);

        return $actor;
    }

    private function source(User $user, string $areaId, bool $shared): string
    {
        return hash('sha256', 'workspace-preferences:'.$areaId.':'.($shared ? 'shared' : 'user:'.$user->id));
    }

    private function favorites(Tenant $tenant, array $actor, string $areaId, array $ids): array
    {
        $state = app(\App\Services\Team\TeamWorkRepository::class)->state($tenant->id);
        $graph = app(\App\Services\Team\WorkGraph::class);

        return array_values(array_filter($ids, function ($id) use ($tenant, $actor, $areaId, $state, $graph): bool {
            foreach (['project' => 'projects', 'task' => 'tasks'] as $type => $key) {
                if (isset($state[$key][$id])) {
                    return ($state[$key][$id]['enterprise_area_id'] ?? null) === $areaId && $graph->can($state, $actor, $type, $id);
                }
            }
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId])->whereIn('kind',
                ['enterprise_request', ...array_values(Resources::KINDS)])->find($id);
            if (! $row) {
                return false;
            }
            if ($row->kind === 'enterprise_request') {
                try {
                    app(RequestWorkflow::class)->view($tenant, $actor, $row);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    return false;
                }
            }

            return $row->kind === 'enterprise_request' || config('enterprise.resources_enabled');
        }));
    }

    public function show(Tenant $tenant, User $user, string $areaId): array
    {
        $actor = $this->authorize($tenant, $user, $areaId);
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'ent_workspace_prefs'])
            ->whereIn('source_key', [$this->source($user, $areaId, true), $this->source($user, $areaId, false)])->get();
        $shared = $rows->firstWhere('source_key', $this->source($user, $areaId, true));
        $personal = $rows->firstWhere('source_key', $this->source($user, $areaId, false));
        $area = $this->access->area($tenant, $actor, $areaId);
        $role = WorkspaceAccess::permits($actor, $areaId, true, true) ? 'manager' : (WorkspaceAccess::permits($actor, $areaId, true) ? 'employee' : 'viewer');
        $defaults = ['density' => 'comfortable', 'view' => 'list', 'favorites' => [], 'filter' => 'all'];
        $defaults = array_replace($defaults, $shared?->data['defaults'] ?? [], $shared?->data['roles'][$role] ?? [], $personal?->data['settings'] ?? []);
        $defaults['favorites'] = $this->favorites($tenant, $actor, $areaId, $defaults['favorites']);

        return ['area_id' => $areaId, 'effective' => $defaults, 'personal_version' => $personal?->version ?? 0,
            'shared_version' => $shared?->version ?? 0, 'role' => $role, 'intake_published' => ($area->settings['enterprise_request_intake'] ?? false) === true,
            'layout_defaults' => array_intersect_key($shared?->data['defaults'] ?? [], array_flip(['density', 'view', 'filter'])),
            'role_defaults' => $shared?->data['roles'] ?? [],
            'can_manage' => WorkspaceAccess::permits($actor, $areaId, true, true),
            'licensing' => ['unit' => 'department_workspace', 'employees' => 'all_included', 'per_seat_charge' => false,
                'additional_units_for' => [], 'pricing_committed' => false, 'billing_changed' => false,
                'description' => 'Department workspace licensing includes employees. Projects, locations, groups, contractors and saved views do not add workspace charges. Pricing remains uncommitted.']];
    }

    public function save(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->authorize($tenant, $user, $input['area_id'], true);
            $shared = $input['scope'] === 'workspace';
            abort_unless(! $shared || WorkspaceAccess::permits($actor, $input['area_id'], true, true), 403);
            $favorites = $input['settings']['favorites'] ?? [];
            abort_unless($this->favorites($tenant, $actor, $input['area_id'], $favorites) === $favorites, 422, 'Choose currently visible records in this workspace.');
            $source = $this->source($user, $input['area_id'], $shared);
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => 'ent_workspace_prefs', 'source_key' => $source])->first();
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $attempt = \App\Models\TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($attempt) {
                abort_unless($attempt->kind === 'ent.preferences' && hash_equals($attempt->input_hash, $hash), 409);

                return $this->show($tenant, $user, $input['area_id']) + ['replayed' => true];
            }
            abort_unless(($row?->version ?? 0) === $input['expected_version'], 409);
            if (! $row) {
                $row = new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                    'kind' => 'ent_workspace_prefs', 'source_key' => $source, 'visibility' => 'area', 'title' => 'Workspace display preferences', 'status' => 'active', 'version' => 0]);
            }
            $row->data = $shared ? ['defaults' => $input['settings'], 'roles' => $input['roles'] ?? []] : ['user_id' => (string) $user->id, 'settings' => $input['settings']];
            $row->version++;
            $row->save();
            if ($shared && array_key_exists('intake_published', $input)) {
                $area = $this->access->area($tenant, $actor, $input['area_id'], true);
                $settings = $area->settings ?? [];
                $settings['enterprise_request_intake'] = $input['intake_published'];
                $area->settings = $settings;
                $area->version++;
                $area->save();
            }
            $this->store->audit($row, $user->id, 'workspace.preferences_saved', ['scope' => $input['scope'], 'intake_published' => $shared ? ($input['intake_published'] ?? null) : null]);
            \App\Models\TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                'record_id' => $row->id, 'user_id' => $user->id, 'kind' => 'ent.preferences', 'status' => 'accepted',
                'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $row->id]]);

            return $this->show($tenant, $user, $input['area_id']) + ['replayed' => false];
        });
    }
}
