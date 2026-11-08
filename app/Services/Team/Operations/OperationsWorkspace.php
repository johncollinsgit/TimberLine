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

    public const FIELDS = ['notes', 'series_id', 'venue', 'address', 'city', 'state', 'timezone', 'environment', 'organizer', 'email', 'phone', 'hotel', 'hotel_address', 'coordinator', 'coordinator_phone', 'coordinator_email', 'website', 'setup_time', 'loadout_time', 'event_hours', 'booth', 'instructions', 'application_status', 'acceptance_status', 'payment_status', 'staffing_status', 'staff_ids', 'native_project_id', 'source_links', 'contact', 'contacts', 'locations', 'last_order_on', 'last_contact_on', 'shipping', 'pickup', 'custom_labels', 'custom_scents', 'requirements', 'next_action', 'account_id', 'amount_cents', 'probability', 'expected_on', 'template', 'subject', 'text', 'html', 'audience_ids', 'steps', 'trigger', 'channel', 'local_time', 'quiet_start', 'quiet_end', 'enabled', 'external', 'reviewed', 'alias_names', 'organizer_emails', 'latitude', 'longitude', 'sessions', 'schedule_complete', 'calendar_color'];

    public function __construct(private OperationsAccess $access) {}

    public function areas(Tenant $tenant, User $user): array
    {
        $this->access->actor($tenant, $user);
        $items = [];
        $calendarContext = app(\App\Services\Team\Search\EventSearchOrder::class)->context($tenant);
        foreach (TeamArea::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $area) {
            try {
                $this->access->area($tenant, $user, $area->id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (in_array($e->getStatusCode(), [403, 404])) {
                    continue;
                } throw $e;
            }
            $items[] = [...$this->areaView($area, $calendarContext), 'capabilities' => ['write' => $this->access->mayWrite($tenant, $user, $area->id)]];
        }

        return $items;
    }

    public function areaView(TeamArea $area, ?array $calendarContext = null): array
    {
        if ($area->profile === 'events' && $calendarContext === null) {
            $calendarContext = app(\App\Services\Team\Search\EventSearchOrder::class)->context(Tenant::findOrFail($area->tenant_id));
        }
        $timezone = $area->profile === 'events' ? ($calendarContext['areas'][(string) $area->id]['timezone'] ?? $calendarContext['timezone']) : null;

        return ['timezone' => $timezone, 'id' => $area->id, 'name' => $area->name, 'profile' => $area->profile, 'slug' => $area->slug, 'version' => $area->version, 'settings' => $area->settings ?? [], 'views' => config('team_operations.profiles.'.$area->profile.'.views', []), 'groups' => config('team_operations.profiles.'.$area->profile.'.groups', [])];
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
        return $this->access->readCollection($tenant, $user, $area, fn () => $this->readRecords($tenant, $user, $area, $filters), in_array($filters['event_scope'] ?? null, ['all', 'window'], true));
    }

    private function readRecords(Tenant $tenant, User $user, TeamArea $area, array $filters): array
    {
        $allEvents = ($filters['event_scope'] ?? null) === 'all';
        $windowEvents = ($filters['event_scope'] ?? null) === 'window';
        $compactEvents = $allEvents || $windowEvents;
        abort_if($windowEvents && (($filters['kind'] ?? null) !== 'event' || $area->profile !== 'events'), 422, 'The event window requires an Events area and event records.');
        abort_if($allEvents && (($filters['kind'] ?? null) !== 'event' || isset($filters['calendar_from']) || isset($filters['calendar_to']) || $area->profile !== 'events'), 422, 'The complete event collection requires an Events area without a date window.');
        $query = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('kind', self::KINDS)->where('status', '!=', 'withdrawn')->orderByRaw('CASE WHEN starts_on IS NULL THEN 1 ELSE 0 END')->orderBy('starts_on')->orderBy('title')->orderBy('id');
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
        $calendarQuery = app(EventCalendarWindow::class);
        $window = $calendarQuery->fromFilters($filters);
        abort_if($windowEvents && ! $window, 422, 'Choose the start and end of the event window.');
        $scope = $cursorService->scope($tenant, $user, $area, $filters);
        $firstPhase = $window ? $cursorService->phase($scope, $filters['cursor'] ?? null) : 0;
        $records = function () use ($query, $window, $calendarQuery, $cursorService, $scope, $filters, $firstPhase) {
            for ($phase = $firstPhase; $phase <= ($window ? 1 : 0); $phase++) {
                $candidates = clone $query;
                if ($window) {
                    $calendarQuery->phase($candidates, $window, $phase);
                }
                if ($phase === $firstPhase) {
                    $cursorService->apply($candidates, $scope, $filters['cursor'] ?? null);
                }
                foreach ($candidates->cursor() as $record) {
                    yield [$record, $phase];
                }
            }
        };
        // Permission precedes pagination. Counts describe this visible page; source
        // checks stay bounded and private source titles remain inside encrypted cursors.
        $items = [];
        $unavailable = false;
        $sourceAccessIncomplete = ($window || $compactEvents) && ! empty($filters['calendar_recheck_sources']) && ! $calendarQuery->sourcesAvailable($tenant, $user, $area, ! $compactEvents);
        $collectionAccessIncomplete = $sourceAccessIncomplete;
        $canEdit = $this->access->mayWrite($tenant, $user, $area->id);
        $admin = $this->access->admin($this->access->actor($tenant, $user));
        $scanned = 0;
        $started = microtime(true);
        $resume = null;
        $resumePhase = 0;
        $more = false;
        $scanLimited = false;
        $failedConnections = [];
        foreach ($records() as [$record, $phase]) {
            if ($scanned >= ($compactEvents ? 1000 : 200) || ($scanned > 0 && microtime(true) - $started > 8)) {
                $more = true;
                $unavailable = true;
                $scanLimited = true;
                break;
            }
            $scanned++;
            if (($window && ! $calendarQuery->matches($record, $window, ! $windowEvents)) || (! empty($filters['target_id']) && ($record->data['target_id'] ?? null) !== $filters['target_id'])) {
                $resume = $record;
                $resumePhase = $phase;

                continue;
            }
            if ($record->connection_id && isset($failedConnections[$record->connection_id])) {
                $resume = $record;
                $resumePhase = $phase;

                continue;
            }
            try {
                $this->access->record($tenant, $user, $record);
                if (count($items) === ($compactEvents ? 200 : 30)) {
                    $more = true;
                    break;
                }
                $items[] = $compactEvents ? $record : $this->view($record, $canEdit, $admin);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404, 409, 429, 502, 503])) {
                    throw $e;
                } $unavailable = true;
                $sourceAccessIncomplete = true;
                if ($record->connection_id && in_array($e->getStatusCode(), [429, 502, 503], true)) {
                    $failedConnections[$record->connection_id] = true;
                }
            }
            $resume = $record;
            $resumePhase = $phase;
        }
        if ($compactEvents) {
            $collectionAccessIncomplete = $collectionAccessIncomplete || ! $this->access->collectionSourcesAvailable();
            $people = $this->collectionPeople($items, $tenant, $area);
            $items = array_map(fn ($record) => $this->eventCollectionView($record, $canEdit, $people), $items);
        }
        $page = (int) ($filters['page'] ?? 1);
        $health = app(OperationsHealth::class)->area($area, $user->id);

        return ['items' => $items, 'count' => count($items), 'page' => $page, 'has_more' => $more, 'next_cursor' => $more && $resume ? $cursorService->encode($scope, $resume, $resumePhase) : null, 'complete' => ! $window && ! $unavailable && ! $more && $health['complete'], 'freshness' => $health, 'source_access_incomplete' => $sourceAccessIncomplete, 'collection_access_incomplete' => $collectionAccessIncomplete,
            'event_collection' => $compactEvents ? ['local_scan_complete' => ! $more, 'scan_limited' => $scanLimited, 'source_coverage_complete' => $health['complete'] && ! $sourceAccessIncomplete && ! $collectionAccessIncomplete] : null,
            'calendar_window' => $window ? [...$window, 'local_scan_complete' => ! $more, 'scan_limited' => $scanLimited, 'unknown_dates_remain_unknown' => true] : null,
            'notice' => $scanLimited ? 'Calendar checking is partial. Continue to check more retained records for dates outside their original source bounds.' : ($unavailable ? 'Some source records could not be verified and are hidden. Results are partial; continue checking or refresh the source.' : $health['notice'])];
    }

    /** Collection cards need schedules, not original notes, candidate parsing or finance detail. */
    private function eventCollectionView(TeamOperationRecord $record, bool $canEdit, array $people): array
    {
        $data = $record->data ?? [];
        $schedule = app(EventSchedule::class)->calendar($data, $record->starts_on?->toDateString(), $record->ends_on?->toDateString());

        return ['id' => $record->id, 'area_id' => $record->area_id, 'kind' => $record->kind, 'title' => $record->title, 'status' => $record->status, 'version' => $record->version,
            'assigned_user_id' => $record->assigned_user_id, 'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(),
            'data' => ['event_schedule' => $schedule, 'source_schedule' => $data['source_schedule'] ?? $data['event_schedule']['source_schedule'] ?? null],
            'calendar_people' => $this->calendarPeople($record, $people), 'source' => $record->connection_id ? ['connection_id' => $record->connection_id, 'key' => $record->source_key] : null,
            'capabilities' => ['edit' => $canEdit && ! $record->connection_id, 'schedule' => $canEdit], 'updated_at' => $record->updated_at?->toIso8601String()];
    }

    public function view(TeamOperationRecord $record, bool $canEdit = true, bool $canFinance = false, bool $includePresentationDetails = true): array
    {
        $data = $record->data ?? [];
        if ($record->kind === 'event') {
            if ($record->connection_id && isset($data['source_id'], $data['source_workspace']) && is_string($data['original_notes'] ?? null)) {
                $data['fields'] = app(OperationsSync::class)->eventFields($data);
                $data['fields_revision'] = app(OperationsSync::class)->eventFieldsRevision($data, $data['fields']);
            }
            $sourceRange = $data['event_schedule'] ?? [];
            if ($includePresentationDetails) {
                $data['event_schedule'] = array_replace($sourceRange, app(EventSchedule::class)->calendar($data, $record->starts_on?->toDateString(), $record->ends_on?->toDateString()));
                if (isset($sourceRange['basis'])) {
                    $data['event_schedule']['source_range_basis'] = $sourceRange['basis'];
                }
            }
        }
        if (! $canFinance) {
            unset($data['_teams_enrichment']['forecast_override'], $data['_teams_enrichment']['square_orders'], $data['_teams_enrichment']['related_native_proposals'], $data['_teams_enrichment']['source_adoption']);
        } elseif ($record->kind === 'event' && $includePresentationDetails) {
            $data['event_schedule']['budget_review_items'] = [];
            $budgetId = $data['budget_id'] ?? $data['_teams_enrichment']['budget_id'] ?? null;
            $budget = $budgetId ? TeamOperationRecord::where(['tenant_id' => $record->tenant_id, 'area_id' => $record->area_id, 'kind' => 'event_budget', 'status' => 'frozen'])->find($budgetId) : null;
            if ($budget && ($budget->data['event_id'] ?? null) === $record->id && $data['event_schedule']['schedule_complete']) {
                $inputs = $budget->data['inputs'] ?? [];
                $dates = $data['event_schedule']['selling_dates'];
                if (($inputs['starts_on'] ?? null) !== ($dates[0] ?? null) || ($inputs['ends_on'] ?? null) !== ($dates ? $dates[count($dates) - 1] : null)) {
                    $data['event_schedule']['budget_review_items'][] = 'Reviewed selling dates differ from the frozen budget dates. Review a separate budget revision; saved amounts are unchanged.';
                }
                if (isset($inputs['selling_days']) && $inputs['selling_days'] !== $data['event_schedule']['selling_day_count']) {
                    $data['event_schedule']['budget_review_items'][] = 'Reviewed distinct selling-day count differs from the frozen budget. Calendar windows do not establish paid work hours.';
                }
            }
            foreach ($data['_teams_enrichment']['related_native_proposals'] ?? [] as $index => $reference) {
                $proposal = TeamOperationRecord::where(['tenant_id' => $record->tenant_id, 'area_id' => $record->area_id, 'kind' => 'event', 'status' => 'withdrawn'])->find($reference['id']);
                if ($proposal && ($proposal->data['projection_id'] ?? null) === $record->id) {
                    $data['_teams_enrichment']['related_native_proposals'][$index]['proposal'] = ['id' => $proposal->id, 'title' => $proposal->title, 'version' => $proposal->version,
                        'calendar' => app(EventSchedule::class)->calendar($proposal->data, $proposal->starts_on?->toDateString(), $proposal->ends_on?->toDateString()),
                        'frozen_budget_id' => $proposal->data['budget_id'] ?? $proposal->data['_teams_enrichment']['budget_id'] ?? null,
                        'notice' => 'Original native proposal retained for review. Its calendar and frozen budget have not been applied to this source event.'];
                }
            }
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

        return ['id' => $record->id, 'area_id' => $record->area_id, 'kind' => $record->kind, 'title' => $record->title, 'status' => $record->status, 'version' => $record->version, 'assigned_user_id' => $record->assigned_user_id, 'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(), 'data' => $data, 'calendar_people' => $includePresentationDetails && $record->kind === 'event' ? $this->calendarPeople($record) : [], 'source' => $record->connection_id ? ['connection_id' => $record->connection_id, 'key' => $record->source_key] : null, 'capabilities' => ['edit' => $canEdit && in_array($record->kind, self::KINDS, true) && ! $record->connection_id && ! in_array($record->kind, ['financial', 'staff', 'document']), 'schedule' => $canEdit && $record->kind === 'event'], 'updated_at' => $record->updated_at?->toIso8601String()];
    }

    private function collectionPeople(array $records, Tenant $tenant, TeamArea $area): array
    {
        $ids = [];
        foreach ($records as $record) {
            $ids = [...$ids, ...$this->calendarPersonIds($record)];
        }
        if (! $ids) {
            return [];
        }

        return $this->calendarPeopleQuery($tenant->id, $area->id, array_unique($ids))->get()->keyBy('id')->map(fn ($user) => ['id' => 'native:'.$user->id, 'name' => $user->name, 'provider' => 'teams'])->all();
    }

    private function calendarPersonIds(TeamOperationRecord $record): array
    {
        return array_filter([$record->assigned_user_id, ...(is_array($record->data['staff_ids'] ?? null) ? $record->data['staff_ids'] : [])], fn ($id) => is_scalar($id) && ctype_digit((string) $id));
    }

    private function calendarPeopleQuery(int $tenantId, string $areaId, array $ids)
    {
        return User::query()->select('users.id', 'users.name')->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
            ->where('tenant_user.tenant_id', $tenantId)->where('tenant_user.membership_active', true)
            ->whereIn('tenant_user.role', ['owner', 'tenant_owner', 'admin', 'member', 'manager'])->whereIn('users.id', $ids)
            ->where('users.is_active', true)->whereNotNull('users.email_verified_at')
            ->where(function ($q) use ($tenantId, $areaId) {
                $q->whereIn('tenant_user.role', ['owner', 'tenant_owner', 'admin'])->orWhereExists(function ($member) use ($tenantId, $areaId) {
                    $member->selectRaw('1')->from('team_area_members')->whereColumn('team_area_members.user_id', 'users.id')->where('team_area_members.tenant_id', $tenantId)->where('team_area_members.area_id', $areaId);
                });
            })->distinct();
    }

    private function calendarPeople(TeamOperationRecord $record, ?array $collectionPeople = null): array
    {
        $data = $record->data ?? [];
        $ids = $this->calendarPersonIds($record);
        $people = $collectionPeople !== null
            ? array_values(array_slice(array_intersect_key($collectionPeople, array_fill_keys($ids, true)), 0, 100))
            : ($ids ? $this->calendarPeopleQuery($record->tenant_id, $record->area_id, $ids)->limit(100)->get()->map(fn ($user) => ['id' => 'native:'.$user->id, 'name' => $user->name, 'provider' => 'teams'])->all() : []);
        if ($record->connection_id && ctype_digit((string) ($data['source_workspace'] ?? '')) && ctype_digit((string) ($data['source_assignee_id'] ?? ''))) {
            $people[] = ['id' => 'asana:'.$data['source_workspace'].':'.$data['source_assignee_id'], 'name' => $data['source_assignee_name'] ?? null, 'provider' => 'asana'];
        }

        return $people;
    }

    public function save(Tenant $tenant, User $user, TeamArea $area, array $input): TeamOperationRecord
    {
        $this->access->area($tenant, $user, $area->id, true);
        abort_if(in_array($input['kind'], ['financial', 'staff', 'document'], true), 403, 'These records are supplied by their authoritative source.');
        $data = array_intersect_key($input['data'] ?? [], array_flip([...self::FIELDS, 'cta_label', 'cta_url', 'template_id', 'days_after', 'stage']));
        if ($input['kind'] === 'event') {
            $data = array_replace($data, app(EventSchedule::class)->validate($data));
            $input = app(EventSchedule::class)->occurrenceBounds($input, app(EventSchedule::class)->calendar($data));
        }
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
