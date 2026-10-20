<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsWorkspace
{
    public const KINDS = ['event', 'series', 'account', 'prospect', 'opportunity', 'followup', 'campaign', 'rule', 'email_template', 'document', 'staff', 'financial'];

    public const FIELDS = ['notes', 'series_id', 'venue', 'address', 'city', 'state', 'timezone', 'environment', 'organizer', 'email', 'phone', 'website', 'setup_time', 'loadout_time', 'event_hours', 'booth', 'instructions', 'application_status', 'acceptance_status', 'payment_status', 'staffing_status', 'staff_ids', 'native_project_id', 'source_links', 'contact', 'contacts', 'locations', 'last_order_on', 'last_contact_on', 'shipping', 'pickup', 'custom_labels', 'custom_scents', 'requirements', 'next_action', 'account_id', 'amount_cents', 'probability', 'expected_on', 'template', 'subject', 'text', 'html', 'audience_ids', 'steps', 'trigger', 'channel', 'local_time', 'quiet_start', 'quiet_end', 'enabled', 'external', 'reviewed', 'alias_names', 'organizer_emails', 'latitude', 'longitude'];

    public function __construct(private OperationsAccess $access) {}

    public function areas(Tenant $tenant, User $user): array
    {
        $this->access->actor($tenant, $user);
        $items = [];
        foreach (TeamArea::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $area) {
            try {
                $this->access->area($tenant, $user, $area->id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (in_array($e->getStatusCode(), [403, 404])) {
                    continue;
                } throw $e;
            }
            $items[] = [...$this->areaView($area), 'capabilities' => ['write' => $this->access->mayWrite($tenant, $user, $area->id)]];
        }

        return $items;
    }

    public function areaView(TeamArea $area): array
    {
        return ['id' => $area->id, 'name' => $area->name, 'profile' => $area->profile, 'slug' => $area->slug, 'version' => $area->version, 'settings' => $area->settings ?? [], 'views' => config('team_operations.profiles.'.$area->profile.'.views', []), 'groups' => config('team_operations.profiles.'.$area->profile.'.groups', [])];
    }

    public function personal(Tenant $tenant, User $user, string $query, bool $mine, int $page): array
    {
        $areas = collect($this->areas($tenant, $user))->keyBy('id');
        $identities = \App\Models\TeamProviderConnection::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'provider' => 'asana', 'status' => 'connected'])->get()->filter(fn ($c) => filled($c->settings['external_user_id'] ?? null) && filled($c->settings['source']['asana_workspace'] ?? null))->map(fn ($c) => $c->settings['source']['asana_workspace'].':'.$c->settings['external_user_id'])->all();
        $records = TeamOperationRecord::where('tenant_id', $tenant->id)->whereIn('area_id', $areas->keys())->whereIn('kind', ['event', 'followup', 'account', 'opportunity'])->whereNotIn('status', ['withdrawn', 'cancelled', 'completed', 'lost'])->orderBy('starts_on')->orderBy('title')->orderBy('id');
        if ($query !== '') {
            $records->where('title', 'like', '%'.addcslashes($query, '%_\\').'%');
        }
        $items = [];
        $partial = false;
        foreach ($records->cursor() as $record) {
            if ($mine && (int) $record->assigned_user_id !== (int) $user->id && ! in_array(($record->data['source_workspace'] ?? '').':'.($record->data['source_assignee_id'] ?? ''), $identities, true)) {
                continue;
            }
            try {
                $this->access->record($tenant, $user, $record);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $partial = true;

                continue;
            }
            $items[] = ['id' => $record->id, 'area_id' => $record->area_id, 'area_name' => $areas[$record->area_id]['name'], 'title' => $record->title, 'status' => $record->status, 'starts_on' => $record->starts_on?->toDateString(), 'source' => $record->connection_id ? 'Connected source' : 'Teams'];
        }

        return ['items' => array_slice($items, ($page - 1) * 12, 12), 'page' => $page, 'has_more' => count($items) > $page * 12, 'partial' => $partial, 'notice' => $partial ? 'Unverified source work is hidden. Check area connection status.' : 'Area records use current area and source permissions. Source freshness is shown in each area.'];
    }

    public function createArea(Tenant $tenant, User $user, array $input): TeamArea
    {
        $this->access->financial($tenant, $user);
        abort_unless(isset(config('team_operations.profiles')[$input['profile']]), 422);
        if ($input['profile'] === 'wholesale') {
            abort_unless($this->access->wholesaleEligible($tenant), 403, 'Enable the Teams Wholesale workspace profile before creating this area.');
        }
        $slug = Str::slug($input['name']);
        abort_unless($slug !== '', 422);

        $preset = config('team_operations.presets.'.$tenant->slug.'.'.$input['profile'], []);

        return TeamArea::query()->firstOrCreate(['tenant_id' => $tenant->id, 'slug' => $slug], ['id' => (string) Str::uuid(), 'name' => $input['name'], 'profile' => $input['profile'], 'settings' => ['stages' => config('team_operations.profiles.'.$input['profile'].'.stages'), 'source_authority' => isset($preset['asana_workspace']) ? 'asana' : 'teams', 'sending_enabled' => false, 'preset' => $preset]]);
    }

    public function records(Tenant $tenant, User $user, TeamArea $area, array $filters): array
    {
        $query = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->where('status', '!=', 'withdrawn')->orderByRaw('CASE WHEN starts_on IS NULL THEN 1 ELSE 0 END')->orderBy('starts_on')->orderBy('title')->orderBy('id');
        if (! $this->access->admin($this->access->actor($tenant, $user))) {
            $query->whereNotIn('kind', ['financial', 'staff'])->where('visibility', '!=', 'admin')->where(fn ($q) => $q->where('visibility', '!=', 'assigned')->orWhere('assigned_user_id', $user->id));
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['q'])) {
            $query->where('title', 'like', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        $cursorService = app(OperationsCursor::class);
        $scope = $cursorService->scope($tenant, $user, $area, $filters);
        $cursorService->apply($query, $scope, $filters['cursor'] ?? null);
        // Permission precedes pagination. Counts describe this visible page; source
        // checks stay bounded and private source titles remain inside encrypted cursors.
        $items = [];
        $unavailable = false;
        $canEdit = $this->access->mayWrite($tenant, $user, $area->id);
        $admin = $this->access->admin($this->access->actor($tenant, $user));
        $scanned = 0;
        $started = microtime(true);
        $resume = null;
        $more = false;
        $failedConnections = [];
        foreach ($query->cursor() as $record) {
            if ($scanned >= 200 || microtime(true) - $started > 8) {
                $more = true;
                $unavailable = true;
                break;
            }
            $scanned++;
            if (! empty($filters['target_id']) && ($record->data['target_id'] ?? null) !== $filters['target_id']) {
                $resume = $record;

                continue;
            }
            if ($record->connection_id && isset($failedConnections[$record->connection_id])) {
                $resume = $record;

                continue;
            }
            try {
                $this->access->record($tenant, $user, $record);
                if (count($items) === 30) {
                    $more = true;
                    break;
                }
                $items[] = $this->view($record, $canEdit, $admin);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404, 409, 429, 502, 503])) {
                    throw $e;
                } $unavailable = true;
                if ($record->connection_id && in_array($e->getStatusCode(), [429, 502, 503], true)) {
                    $failedConnections[$record->connection_id] = true;
                }
            }
            $resume = $record;
        }
        $page = (int) ($filters['page'] ?? 1);
        $health = app(OperationsHealth::class)->area($area, $user->id);

        return ['items' => $items, 'count' => count($items), 'page' => $page, 'has_more' => $more, 'next_cursor' => $more && $resume ? $cursorService->encode($scope, $resume) : null, 'complete' => ! $unavailable && ! $more && $health['complete'], 'freshness' => $health, 'notice' => $unavailable ? 'Some source records could not be verified and are hidden. Results are partial; continue checking or refresh the source.' : $health['notice']];
    }

    public function view(TeamOperationRecord $record, bool $canEdit = true, bool $canFinance = false): array
    {
        $data = $record->data ?? [];
        if (! $canFinance) {
            unset($data['_teams_enrichment']['forecast_override'], $data['_teams_enrichment']['square_orders']);
        }
        if ($record->kind === 'document') {
            $data = array_intersect_key($data, array_flip(['filename', 'mime', 'checksum', 'scan', 'target_id', 'matching', 'observed_at']));
        }
        if ($record->kind === 'document' && isset($data['matching'])) {
            $data['matching'] = array_intersect_key($data['matching'], array_flip(['automatic', 'notice', 'reviewed_by', 'reviewed_at']));
        }
        // Wholesale source notes can contain tax IDs and private addresses. Keep originals
        // in the encrypted evidence store; the relationship view shows explicit fields.
        if ($record->kind === 'account' && $record->connection_id) {
            unset($data['original_notes']);
        }

        return ['id' => $record->id, 'area_id' => $record->area_id, 'kind' => $record->kind, 'title' => $record->title, 'status' => $record->status, 'version' => $record->version, 'assigned_user_id' => $record->assigned_user_id, 'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(), 'data' => $data, 'source' => $record->connection_id ? ['connection_id' => $record->connection_id, 'key' => $record->source_key] : null, 'capabilities' => ['edit' => $canEdit && ! $record->connection_id && ! in_array($record->kind, ['financial', 'staff', 'document'])], 'updated_at' => $record->updated_at?->toIso8601String()];
    }

    public function save(Tenant $tenant, User $user, TeamArea $area, array $input): TeamOperationRecord
    {
        $this->access->area($tenant, $user, $area->id, true);
        abort_if(in_array($input['kind'], ['financial', 'staff', 'document'], true), 403, 'These records are supplied by their authoritative source.');
        $data = array_intersect_key($input['data'] ?? [], array_flip([...self::FIELDS, 'cta_label', 'cta_url', 'template_id', 'days_after', 'stage']));
        if ($input['kind'] === 'email_template') {
            $this->access->financial($tenant, $user);
            abort_unless(in_array($input['status'], ['draft', 'published', 'archived'], true), 422);
            app(OperationsTemplates::class)->validate($data);
        }
        if (in_array($input['kind'], ['rule', 'campaign'], true)) {
            $this->access->financial($tenant, $user);
            abort_if(($data['enabled'] ?? false) && ! ($data['reviewed'] ?? false), 422, 'Review the audience, sender and template before enabling automation.');
            abort_if(($data['enabled'] ?? false) && ($data['external'] ?? false), 409, 'External sequences require the existing campaign approval and suppression workflow. Keep this rule as a draft.');
            foreach ($data['audience_ids'] ?? [] as $recipient) {
                abort_unless(in_array((string) $recipient, $this->access->actor($tenant, $user)['verified_user_ids'], true), 422);
                $this->access->area($tenant, User::findOrFail($recipient), $area->id);
            }
            if ($input['kind'] === 'rule') {
                app(OperationsReminders::class)->validate($area, $data);
            }
        }
        if ($id = ($input['assigned_user_id'] ?? null)) {
            abort_unless(in_array((string) $id, $this->access->actor($tenant, $user)['verified_user_ids'], true), 422);
            $this->access->area($tenant, User::findOrFail($id), $area->id);
        }
        if ($project = $data['native_project_id'] ?? null) {
            abort_unless(app(\App\Services\Team\WorkGraph::class)->can(app(\App\Services\Team\TeamWorkRepository::class)->state($tenant->id), $this->access->actor($tenant, $user), 'project', $project), 403);
        }
        foreach (['series_id', 'account_id'] as $field) {
            if (! empty($data[$field])) {
                $parent = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->findOrFail($data[$field]);
                $this->access->record($tenant, $user, $parent);
            }
        }

        return DB::transaction(function () use ($tenant, $user, $area, $input, $data) {
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = \App\Models\TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->lockForUpdate()->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'record.save' && hash_equals($receipt->input_hash, $hash), 409, 'Request ID belongs to different content.');
                $saved = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->findOrFail($receipt->record_id);
                $this->access->record($tenant, $user, $saved);

                return $saved;
            }
            $record = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->lockForUpdate()->find($input['id']);
            if ($record) {
                $this->access->record($tenant, $user, $record, true);
                abort_unless($record->version === (int) $input['expected_version'], 409, 'This record changed. Your draft is retained; review the current version.');
            } else {
                abort_unless((int) $input['expected_version'] === 0, 409);
                $record = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'source_key' => hash('sha256', 'native:'.$input['id']), 'version' => 0]);
            }
            $data['_created_by'] = $record->exists ? ($record->data['_created_by'] ?? $user->id) : $user->id;
            abort_unless(! $record->exists || $record->kind === $input['kind'], 422);
            $record->fill(['kind' => $input['kind'], 'title' => $input['title'], 'status' => $input['status'], 'starts_on' => $input['starts_on'] ?? null, 'ends_on' => $input['ends_on'] ?? null, 'assigned_user_id' => $input['assigned_user_id'] ?? null, 'data' => $data, 'version' => $record->version + 1]);
            if (in_array($record->kind, ['rule', 'campaign'], true)) {
                $record->visibility = 'admin';
            }
            if ($record->kind === 'email_template') {
                $record->visibility = $record->status === 'published' ? 'area' : 'admin';
            }
            $record->save();
            $this->revision($record, $user->id);
            \App\Models\TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'record_id' => $record->id, 'kind' => 'record.save', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $record->id, 'version' => $record->version]]);

            return $record;
        });
    }

    public function revision(TeamOperationRecord $record, ?int $actor = null): void
    {
        DB::table('team_operation_revisions')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $record->tenant_id, 'record_id' => $record->id, 'version' => $record->version, 'user_id' => $actor, 'payload' => Crypt::encryptString(json_encode($this->view($record, true, true), JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }
}
