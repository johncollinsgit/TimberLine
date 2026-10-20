<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;

class OperationsAccess
{
    private array $verifiedFiles = [];

    public function actor(Tenant $tenant, User $user): array
    {
        abort_unless(config('team_operations.enabled') && config('team_work.premium_enabled'), 404);

        return app(TeamWorkAccess::class)->context($user->fresh() ?? $user, $tenant);
    }

    public function admin(array $actor): bool
    {
        return in_array($actor['role'], ['owner', 'admin'], true);
    }

    public function area(Tenant $tenant, User $user, string $id, bool $write = false): TeamArea
    {
        $actor = $this->actor($tenant, $user);
        if ($write) {
            abort_unless(in_array($actor['role'], ['owner', 'admin', 'member'], true), 403);
        }
        $area = TeamArea::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        if ($area->profile === 'wholesale') {
            abort_unless($this->wholesaleEligible($tenant), 403);
        }
        $role = DB::table('team_area_members')->where(['tenant_id' => $tenant->id, 'area_id' => $id, 'user_id' => $user->id])->value('role');
        abort_unless($this->admin($actor) || ($write ? in_array($role, ['editor', 'coordinator'], true) : $role !== null), 403);

        return $area;
    }

    public function financial(Tenant $tenant, User $user): void
    {
        abort_unless($this->admin($this->actor($tenant, $user)), 403);
    }

    public function wholesaleEligible(Tenant $tenant): bool
    {
        $modules = app(TenantModuleAccessResolver::class);

        return $modules->canAccess($tenant->id, 'teams_wholesale_workspace') || $modules->canAccess($tenant->id, 'wholesale_operations');
    }

    public function mayWrite(Tenant $tenant, User $user, string $areaId): bool
    {
        try {
            $this->area($tenant, $user, $areaId, true);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }

            return false;
        }
    }

    public function connection(Tenant $tenant, User $user, string $id, bool $manage = false): TeamProviderConnection
    {
        $connection = TeamProviderConnection::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        $this->area($tenant, $user, $connection->area_id);
        abort_unless((int) $connection->user_id === (int) $user->id, 403);

        return $connection;
    }

    public function record(Tenant $tenant, User $user, TeamOperationRecord $record, bool $write = false): void
    {
        abort_unless((int) $record->tenant_id === (int) $tenant->id, 404);
        $this->area($tenant, $user, $record->area_id, $write);
        if ($record->status === 'withdrawn') {
            abort(404);
        }
        $actor = $this->actor($tenant, $user);
        if ($write && in_array($record->kind, ['email_template', 'rule', 'campaign'], true)) {
            $this->financial($tenant, $user);
        }
        if (in_array($record->kind, ['financial', 'staff'], true) || $record->visibility === 'admin') {
            $this->financial($tenant, $user);
        }
        if ($record->visibility === 'assigned') {
            abort_unless($this->admin($actor) || (int) $record->assigned_user_id === (int) $user->id, 403);
        }
        if ($project = ($record->data ?? [])['native_project_id'] ?? null) {
            abort_unless(app(WorkGraph::class)->can(app(TeamWorkRepository::class)->state($tenant->id), $actor, 'project', $project), 403);
        }
        if ($record->connection_id) {
            $connection = TeamProviderConnection::query()->where('tenant_id', $tenant->id)->findOrFail($record->connection_id);
            if ($connection->provider === 'asana') {
                $providers = app(OperationsProviders::class);
                $projects = array_values(array_unique([$record->data['source_project'] ?? '', ...($record->data['source_projects'] ?? [])]));
                $primary = $connection;
                if ((int) $connection->user_id !== (int) $user->id || $connection->status !== 'connected' || ($record->data['_generation'] ?? null) !== $connection->generation) {
                    $connection = TeamProviderConnection::query()->where(['tenant_id' => $tenant->id, 'area_id' => $record->area_id, 'user_id' => $user->id, 'provider' => 'asana', 'status' => 'connected'])->where('id', '!=', $primary->id)->get()->first(fn ($c) => count(array_intersect($providers->selectedProjects($c), $projects)) > 0 && ($c->settings['source']['asana_workspace'] ?? '') === ($record->data['source_workspace'] ?? ''));
                    abort_unless($connection, 403);
                }
                $selectedProject = array_values(array_intersect($providers->selectedProjects($connection), $projects))[0] ?? '';
                abort_unless($selectedProject !== '', 403);
                if (($record->data['source_type'] ?? 'task') === 'project') {
                    $providers->verifyAsanaProject($connection, $selectedProject);
                } else {
                    $providers->verifyAsanaRecord($connection, $record->data['source_id'] ?? '', $selectedProject);
                }
            } else {
                abort_unless($connection->status === 'connected', 403);
                abort_unless(($record->data['_generation'] ?? null) === $connection->generation, 409, 'Source selection changed. Refresh it before displaying retained work.');
                if ($connection->provider === 'sheets') {
                    $this->financial($tenant, $user);
                    $file = $connection->settings['source']['spreadsheet_id'] ?? '';
                    if ((int) $connection->user_id !== (int) $user->id) {
                        $connection = TeamProviderConnection::query()->where(['tenant_id' => $tenant->id, 'area_id' => $record->area_id, 'user_id' => $user->id, 'provider' => 'sheets', 'status' => 'connected'])->get()->first(fn ($c) => ($c->settings['source']['spreadsheet_id'] ?? '') === $file);
                        abort_unless($connection, 403);
                    }
                    abort_unless(preg_match('/^[A-Za-z0-9_-]{10,160}$/D', $file), 403);
                    $key = $connection->id.':'.$connection->generation;
                    if (! isset($this->verifiedFiles[$key])) {
                        app(OperationsProviders::class)->http($connection, 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/'.$file, ['fields' => 'spreadsheetId']);
                        $this->verifiedFiles[$key] = true;
                    }
                } else {
                    $message = $record->data['message_id'] ?? null;
                    abort_unless(is_string($message) && preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $message), 403);
                    $messageKey = $connection->id.':'.$connection->generation.':'.$message;
                    if (! isset($this->verifiedFiles[$messageKey])) {
                        app(OperationsProviders::class)->http($connection, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.$message, ['format' => 'minimal']);
                        $this->verifiedFiles[$messageKey] = true;
                    }
                    $owner = (int) $connection->user_id === (int) $user->id;
                    if (! $owner) {
                        abort_unless($record->kind === 'document' && $record->status === 'linked' && ($record->data['scan'] ?? '') === 'clean', 403);
                        $target = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $record->area_id])->whereIn('kind', ['event', 'account'])->findOrFail($record->data['target_id'] ?? '');
                        $this->record($tenant, $user, $target);
                    }
                }
            }
        }
        if ($write) {
            abort_if($record->connection_id !== null || in_array($record->kind, ['financial', 'staff', 'document'], true), 403, 'Source records are read only. Edit the authoritative source.');
        }
    }
}
