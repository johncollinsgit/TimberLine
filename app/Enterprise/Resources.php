<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tenant-serialized resource metadata, custody and exclusive reservations; never a campus import. */
class Resources
{
    public const KINDS = ['location' => 'ent_resource_location', 'asset' => 'ent_resource_asset', 'reservation' => 'ent_resource_booking', 'conflict' => 'ent_resource_conflict'];

    public const LEVELS = ['institution', 'campus_area', 'facility', 'floor', 'room'];

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private LocationCatalog $catalog) {}

    private function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.resources_enabled'), 404);

        return $this->access->actor($tenant, $user, $write);
    }

    private function row(Tenant $tenant, array $actor, string $id, ?string $kind = null): TeamOperationRecord
    {
        $query = TeamOperationRecord::query()->where('tenant_id', $tenant->id)->whereIn('kind', self::KINDS);
        if ($kind !== null) {
            $query->where('kind', self::KINDS[$kind]);
        }
        $row = $query->findOrFail($id);
        $this->access->area($tenant, $actor, $row->area_id);

        return $row;
    }

    private function linked(Tenant $tenant, array $actor, string $areaId, string $id, ?string $kind = null, bool $active = true): TeamOperationRecord
    {
        $row = $this->row($tenant, $actor, $id, $kind);
        abort_unless($row->area_id === $areaId, 404);
        abort_if($active && in_array($row->status, ['inactive', 'withdrawn', 'retired', 'cancelled'], true), 409, 'The referenced resource is unavailable.');

        return $row;
    }

    private function dto(Tenant $tenant, array $actor, TeamOperationRecord $row): array
    {
        $this->access->area($tenant, $actor, $row->area_id);
        $kind = array_search($row->kind, self::KINDS, true);
        $data = $row->data ?? [];
        // Store stable identity only; never retain or reveal a revoked member's name from history.
        if ($kind === 'asset' && ($data['holder_id'] ?? null) !== null) {
            $holder = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereNotNull('users.email_verified_at')->find($data['holder_id']);
            $recipient = array_replace($actor, ['user_id' => (string) $data['holder_id'], 'role' => $actor['active_roles'][(string) $data['holder_id']] ?? 'guest']);
            $data['holder_name'] = $holder && WorkspaceAccess::permits($recipient, $row->area_id) ? $holder->name : 'Unavailable member';
        }
        $section = match ($kind) {
            'asset' => 'assets', 'reservation', 'conflict' => 'calendar', default => 'buildings',
        };

        $url = route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $row->area_id, 'section' => $section, 'selected' => $row->id]);

        return ['id' => $row->id, 'kind' => $kind, 'area_id' => $row->area_id, 'title' => $row->title, 'status' => $row->status,
            'version' => $row->version, 'data' => $data, 'updated_at' => $row->updated_at?->toIso8601String(),
            'url' => $url, 'qr_url' => $url,
            'can_coordinate' => WorkspaceAccess::permits($actor, $row->area_id, true, true),
            'can_checkout' => $kind === 'asset' && $row->status === 'available' && WorkspaceAccess::permits($actor, $row->area_id, true),
            'can_return' => $kind === 'asset' && $row->status === 'checked_out' && WorkspaceAccess::permits($actor, $row->area_id, true)
                && (($data['holder_id'] ?? null) === $actor['user_id'] || WorkspaceAccess::permits($actor, $row->area_id, true, true))];
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $actor = $this->actor($tenant, $user);

        return $this->dto($tenant, $actor, $this->row($tenant, $actor, $id));
    }

    public function listing(Tenant $tenant, User $user, string $areaId, string $query = ''): array
    {
        $actor = $this->actor($tenant, $user);
        $this->access->area($tenant, $actor, $areaId);
        $items = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId])->whereIn('kind', self::KINDS)->orderBy('title')->orderBy('id')->limit(1000)->cursor() as $row) {
            $data = $row->data ?? [];
            $search = implode(' ', [$row->title, $data['map_number'] ?? '', $data['aliases'] ?? '', $data['official_function_details'] ?? '', $data['related_spaces'] ?? '']);
            if ($query !== '' && mb_stripos($search, $query) === false) {
                continue;
            }
            $items[] = $this->dto($tenant, $actor, $row);
            if (count($items) > 200) {
                break;
            }
        }
        $visible = array_slice($items, 0, 200);
        $counts = ['location' => 0, 'asset' => 0, 'reservation' => 0, 'conflict' => 0, 'checked_out' => 0, 'open_conflicts' => 0];
        foreach ($visible as $item) {
            $counts[$item['kind']]++;
            $counts['checked_out'] += (int) ($item['kind'] === 'asset' && $item['status'] === 'checked_out');
            $counts['open_conflicts'] += (int) ($item['kind'] === 'conflict' && $item['status'] === 'open');
        }

        return ['items' => $visible, 'counts' => $counts, 'has_more' => count($items) > 200, 'partial' => true,
            'coverage' => 'Counts describe only the up to 200 matching authorized records returned from a scan of at most 1000 in this workspace.',
            'can_coordinate' => WorkspaceAccess::permits($actor, $areaId, true, true)];
    }

    public function history(Tenant $tenant, User $user, string $id): array
    {
        $this->show($tenant, $user, $id);
        $rows = DB::table('team_operation_revisions')->where(['tenant_id' => $tenant->id, 'record_id' => $id])->orderByDesc('version')->limit(100)->get();

        return ['items' => $rows->map(fn ($row) => ['version' => $row->version, 'actor_id' => $row->user_id, 'at' => $row->created_at,
            'event' => json_decode(Crypt::decryptString($row->payload), true, 64, JSON_THROW_ON_ERROR)])->all(), 'coverage' => 'Latest 100 immutable resource revisions; current workspace permission required.'];
    }

    /** Operational attention is independent of the public directory and location inventory. */
    public function attention(Tenant $tenant, User $user, string $areaId): array
    {
        $actor = $this->actor($tenant, $user);
        $this->access->area($tenant, $actor, $areaId);
        $items = [];
        $clock = now()->utc();
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId])->where(function ($query): void {
            $query->where(['kind' => self::KINDS['conflict'], 'status' => 'open'])
                ->orWhere(fn ($assets) => $assets->where(['kind' => self::KINDS['asset'], 'status' => 'checked_out']))
                ->orWhere(fn ($bookings) => $bookings->where('kind', self::KINDS['reservation'])->whereIn('status', ['scheduled', 'conflicted']));
        })->orderByRaw('CASE kind WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [self::KINDS['conflict'], self::KINDS['asset']])
            ->orderBy('starts_on')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $row) {
            $data = $row->data;
            if ($row->kind === self::KINDS['asset'] && (empty($data['expected_return_at']) || CarbonImmutable::parse($data['expected_return_at'])->gt($clock))) {
                continue;
            }
            if ($row->kind === self::KINDS['reservation'] && CarbonImmutable::parse($data['ends_at'])->lt($clock)) {
                continue;
            }
            $items[] = $this->dto($tenant, $actor, $row);
            if (count($items) > 200) {
                break;
            }
        }

        return ['items' => array_slice($items, 0, 200), 'partial' => true, 'has_more' => count($items) > 200,
            'coverage' => 'Up to 200 authorized open conflicts, overdue custody records and current/upcoming reservations from a scan of at most 1000 operational records; location inventory is excluded.',
            'as_of' => $clock->toIso8601String()];
    }

    private function freshRecord(Tenant $tenant, array $actor, array $input, string $kind): TeamOperationRecord
    {
        $existing = TeamOperationRecord::query()->where('id', $input['id'])->exists();
        if ($existing) {
            $row = $this->linked($tenant, $actor, $input['area_id'], $input['id'], $kind, false);
            abort_unless($row->version === $input['expected_version'], 409, 'This resource changed. Refresh before saving.');

            return $row;
        }
        abort_unless($input['expected_version'] === 0, 409);

        return new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => self::KINDS[$kind],
            'source_key' => hash('sha256', 'ent-resource:'.$input['id']), 'visibility' => 'area', 'version' => 0]);
    }

    private function save(TeamOperationRecord $row, User $user, string $action, ?array $before): void
    {
        $row->version++;
        $row->save();
        $this->store->audit($row, $user->id, $action, ['before' => $before, 'after' => ['title' => $row->title, 'status' => $row->status, 'data' => $row->data]]);
    }

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $action = $input['action'];
            $coordinate = ! in_array($action, ['asset.checkout', 'asset.return'], true);
            $this->access->area($tenant, $actor, $input['area_id'], $coordinate);
            abort_unless(WorkspaceAccess::permits($actor, $input['area_id'], true, $coordinate), 403);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.resources' && hash_equals($receipt->input_hash, $hash), 409);
                if (str_starts_with($action, 'fixture.')) {
                    $this->assertFixture($tenant, $actor, $input['area_id']);

                    return $receipt->result + ['replayed' => true];
                }
                $row = $this->linked($tenant, $actor, $input['area_id'], $input['id'], null, false);
                if (in_array($action, ['asset.checkout', 'asset.return'], true)) {
                    abort_unless(($receipt->result['custody_actor_id'] ?? '') === $actor['user_id'], 403);
                }

                return $this->dto($tenant, $actor, $row) + ['replayed' => true];
            }
            if (str_starts_with($action, 'fixture.')) {
                $result = $this->fixture($tenant, $user, $actor, $input);
            } else {
                $kind = match (true) {
                    str_starts_with($action, 'location.') => 'location', str_starts_with($action, 'asset.') => 'asset',
                    str_starts_with($action, 'reservation.') => 'reservation', default => 'conflict',
                };
                $row = $this->freshRecord($tenant, $actor, $input, $kind);
                $before = $row->exists ? ['title' => $row->title, 'status' => $row->status, 'data' => $row->data] : null;
                if (in_array($action, ['asset.checkout', 'asset.return', 'reservation.cancel', 'conflict.resolve'], true)) {
                    abort_unless($row->exists, 404);
                }
                match ($action) {
                    'location.save' => $this->location($tenant, $actor, $row, $input),
                    'asset.save' => $this->asset($tenant, $actor, $row, $input),
                    'asset.checkout', 'asset.return' => $this->custody($tenant, $actor, $row, $input),
                    'reservation.save' => $this->reservation($tenant, $user, $actor, $row, $input),
                    'reservation.cancel' => $this->cancel($row, $input),
                    'conflict.resolve' => $this->resolveConflict($tenant, $user, $actor, $row, $input),
                    default => abort(422),
                };
                $this->save($row, $user, $action, $before);
                $result = $this->dto($tenant, $actor, $row);
            }
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                'record_id' => str_starts_with($action, 'fixture.') ? null : $input['id'], 'user_id' => $user->id, 'kind' => 'ent.resources', 'status' => 'accepted',
                'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $input['id'], 'custody_actor_id' => $actor['user_id'], ...$result]]);

            return $result + ['replayed' => false];
        });
    }

    private function location(Tenant $tenant, array $actor, TeamOperationRecord $row, array $input): void
    {
        abort_if(in_array($input['level'], ['floor', 'room'], true) && empty($input['parent_id']), 422, 'Floors and rooms require a saved parent record.');
        $parent = null;
        if (! empty($input['parent_id'])) {
            abort_if($input['parent_id'] === $row->id, 422);
            $parent = $this->linked($tenant, $actor, $row->area_id, $input['parent_id'], 'location');
            $expected = ['campus_area' => ['institution'], 'facility' => ['institution', 'campus_area'], 'floor' => ['facility'], 'room' => ['facility', 'floor']];
            abort_unless(in_array($parent->data['level'], $expected[$input['level']] ?? [], true), 422, 'Choose a valid parent in the location hierarchy.');
            $cursor = $parent;
            for ($depth = 0; $cursor; $depth++) {
                abort_if($depth > 5 || $cursor->id === $row->id, 422, 'Location hierarchy cannot contain a cycle.');
                $cursor = empty($cursor->data['parent_id']) ? null : $this->linked($tenant, $actor, $row->area_id, $cursor->data['parent_id'], 'location');
            }
        }
        abort_if($row->exists && ($row->data['level'] ?? null) !== $input['level'], 409, 'Location level is immutable.');
        $data = $row->data ?? [];
        if (! empty($input['catalog_map_number'])) {
            abort_unless($input['level'] === 'facility', 422, 'Official map entries are unverified facility records, not surveyed rooms or floors.');
            $entry = $this->catalog->entry($input['catalog_map_number']);
            abort_if($row->exists && isset($data['map_number']) && $data['map_number'] !== $entry['map_number'], 409, 'Catalog identity is immutable.');
            $data = array_replace($data, $entry, ['source_url' => $entry['source_url'] ?? $this->catalog->all()['source_url'],
                'source_page' => array_key_exists('source_page', $entry) ? $entry['source_page'] : 2, 'hierarchy_verified' => false]);
            $input['title'] = $entry['name'];
        }
        if (isset($data['map_number'])) {
            $input['title'] = $this->catalog->entry($data['map_number'])['name'];
        }
        $row->title = $input['title'];
        $row->status = 'active';
        $row->data = array_replace($data, ['level' => $input['level'], 'parent_id' => $parent?->id, 'description' => $input['description'] ?? '',
            'latitude' => null, 'longitude' => null, 'ownership' => null, 'campus_area_verified' => false]);
    }

    private function asset(Tenant $tenant, array $actor, TeamOperationRecord $row, array $input): void
    {
        abort_if($row->exists && $row->status === 'checked_out', 409, 'Return this asset before changing its metadata.');
        $location = $this->linked($tenant, $actor, $row->area_id, $input['location_id'], 'location');
        $row->title = $input['title'];
        $row->status = 'available';
        $row->data = array_replace($row->data ?? [], ['current_location_id' => $location->id, 'condition' => $input['condition'] ?? '',
            'holder_id' => null, 'checked_out_at' => null, 'expected_return_at' => null]);
    }

    private function custody(Tenant $tenant, array $actor, TeamOperationRecord $row, array $input): void
    {
        $data = $row->data;
        $manager = WorkspaceAccess::permits($actor, $row->area_id, true, true);
        if ($input['action'] === 'asset.checkout') {
            abort_unless($row->status === 'available', 409, 'This asset is already checked out or unavailable.');
            $holderId = (string) $input['holder_id'];
            abort_unless($manager || $holderId === $actor['user_id'], 403);
            $holder = array_replace($actor, ['user_id' => $holderId, 'role' => $actor['active_roles'][$holderId] ?? 'guest']);
            abort_unless(in_array($holderId, $actor['verified_user_ids'], true) && in_array($holder['role'], ['owner', 'admin', 'member'], true)
                && WorkspaceAccess::permits($holder, $row->area_id, true), 422, 'Choose an existing active verified workspace editor.');
            $expected = CarbonImmutable::parse($input['expected_return_at'])->utc();
            abort_unless($expected->isFuture(), 422);
            $data['holder_id'] = $holderId;
            $data['checked_out_at'] = now()->utc()->toIso8601String();
            $data['expected_return_at'] = $expected->toIso8601String();
            $row->status = 'checked_out';
        } else {
            abort_unless($row->status === 'checked_out', 409);
            abort_unless($manager || ($data['holder_id'] ?? null) === $actor['user_id'], 403);
            $data['holder_id'] = null;
            $data['checked_out_at'] = null;
            $data['expected_return_at'] = null;
            $data['returned_at'] = now()->utc()->toIso8601String();
            $row->status = 'available';
        }
        $location = $this->linked($tenant, $actor, $row->area_id, $input['location_id'], 'location');
        $data['current_location_id'] = $location->id;
        $data['condition'] = $input['condition'] ?? '';
        $row->data = $data;
    }

    private function activeBookings(Tenant $tenant, string $areaId, string $startsOn, string $endsOn): array
    {
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::KINDS['reservation']])->whereIn('status', ['scheduled', 'conflicted'])
            ->whereDate('starts_on', '<=', $endsOn)->whereDate('ends_on', '>=', $startsOn)->limit(1001)->get();
        abort_if($rows->count() > 1000, 409, 'Reservation scope exceeds the bounded safety scan; an indexed scheduler is required.');

        return $rows->all();
    }

    private function overlapping(array $left, array $right): bool
    {
        return $left['resource_id'] === $right['resource_id'] && $left['starts_at'] < $right['ends_at'] && $left['ends_at'] > $right['starts_at'];
    }

    private function reservation(Tenant $tenant, User $user, array $actor, TeamOperationRecord $row, array $input): void
    {
        abort_if($row->exists, 409, 'Cancel and create a new reservation to change its reviewed window.');
        $resource = $this->linked($tenant, $actor, $row->area_id, $input['resource_id']);
        abort_unless($resource->kind === self::KINDS['asset'] || ($resource->kind === self::KINDS['location'] && $resource->data['level'] === 'room'), 422, 'Reserve an asset or a room record in this workspace.');
        $start = CarbonImmutable::parse($input['starts_at'])->utc();
        $end = CarbonImmutable::parse($input['ends_at'])->utc();
        abort_unless($end->gt($start) && $end->diffInDays($start, true) <= 366, 422);
        $data = ['resource_id' => $resource->id, 'starts_at' => $start->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
            'purpose' => $input['purpose'] ?? '', 'category' => $input['category'] ?? 'event', 'exclusive' => true];
        $row->title = $input['title'];
        $row->status = 'scheduled';
        $row->starts_on = $start->toDateString();
        $row->ends_on = $end->toDateString();
        $row->data = $data;
        foreach ($this->activeBookings($tenant, $row->area_id, $start->toDateString(), $end->toDateString()) as $other) {
            if (! $this->overlapping($data, $other->data)) {
                continue;
            }
            $row->status = 'conflicted';
            $conflict = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
                'kind' => self::KINDS['conflict'], 'title' => 'Exclusive reservation conflict', 'source_key' => hash('sha256', $row->id.':'.$other->id),
                'visibility' => 'area', 'version' => 1, 'status' => 'open', 'data' => ['resource_id' => $resource->id, 'reservation_ids' => [$other->id, $row->id],
                    'reason' => 'Overlapping exclusive UTC reservation windows', 'starts_at' => max($data['starts_at'], $other->data['starts_at']),
                    'ends_at' => min($data['ends_at'], $other->data['ends_at']), 'resolution_note' => null]]);
            $this->store->audit($conflict, $user->id, 'conflict.detected', ['resource_id' => $resource->id, 'reservation_ids' => [$other->id, $row->id]]);
        }
    }

    private function cancel(TeamOperationRecord $row, array $input): void
    {
        abort_unless(in_array($row->status, ['scheduled', 'conflicted'], true), 409);
        $row->status = 'cancelled';
        $row->data = array_replace($row->data, ['cancellation_note' => $input['note']]);
    }

    private function resolveConflict(Tenant $tenant, User $user, array $actor, TeamOperationRecord $row, array $input): void
    {
        abort_unless($row->status === 'open', 409);
        $bookings = [];
        foreach ($row->data['reservation_ids'] as $id) {
            $bookings[] = $this->linked($tenant, $actor, $row->area_id, $id, 'reservation', false);
        }
        $active = array_filter($bookings, fn ($booking) => $booking->status !== 'cancelled');
        abort_if(count($active) === 2 && $this->overlapping($bookings[0]->data, $bookings[1]->data), 409, 'Cancel the conflicting booking explicitly before resolving this conflict.');
        $row->status = 'resolved';
        $row->data = array_replace($row->data, ['resolution_note' => $input['note'], 'resolved_at' => now()->utc()->toIso8601String()]);
        foreach ($active as $booking) {
            if ($booking->status !== 'conflicted') {
                continue;
            }
            $open = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $row->area_id, 'kind' => self::KINDS['conflict'], 'status' => 'open'])
                ->where('id', '!=', $row->id)->get()->contains(fn ($conflict) => in_array($booking->id, $conflict->data['reservation_ids'], true));
            if (! $open) {
                $before = ['title' => $booking->title, 'status' => $booking->status, 'data' => $booking->data];
                $booking->status = 'scheduled';
                $this->save($booking, $user, 'reservation.conflict_cleared', $before);
            }
        }
    }

    private function assertFixture(Tenant $tenant, array $actor, string $areaId): void
    {
        $area = $this->access->area($tenant, $actor, $areaId, true);
        abort_unless(app()->environment(['local', 'testing']) && ($area->settings['synthetic_fixture'] ?? false) === true, 403, 'Catalog fixtures require an explicitly synthetic local/testing workspace.');
    }

    private function fixture(Tenant $tenant, User $user, array $actor, array $input): array
    {
        $this->assertFixture($tenant, $actor, $input['area_id']);
        $count = 0;
        foreach ($this->catalog->all()['entries'] as $entry) {
            $key = hash('sha256', 'catalog:'.$input['area_id'].':'.$entry['map_number']);
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => self::KINDS['location'], 'source_key' => $key])->first();
            if ($input['action'] === 'fixture.withdraw') {
                if (! $row || $row->status === 'withdrawn') {
                    continue;
                }
                $references = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $input['area_id']])->whereIn('kind', self::KINDS)->where('id', '!=', $row->id)->get();
                abort_if($references->contains(fn ($other) => ($other->data['parent_id'] ?? null) === $row->id || ($other->data['current_location_id'] ?? null) === $row->id
                    || ($other->data['resource_id'] ?? null) === $row->id), 409, 'A fixture location has operational references and cannot be withdrawn.');
                $before = ['status' => $row->status];
                $row->status = 'withdrawn';
                $this->save($row, $user, 'fixture.withdraw', $before);
                $count++;

                continue;
            }
            if ($row) {
                if ($row->status === 'withdrawn' && ($row->data['synthetic_fixture'] ?? false) === true) {
                    $row->status = 'active';
                    $this->save($row, $user, 'fixture.restore', ['status' => 'withdrawn']);
                    $count++;
                }

                continue;
            }
            $row = new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => self::KINDS['location'],
                'source_key' => $key, 'title' => $entry['name'], 'status' => 'active', 'visibility' => 'area', 'version' => 0,
                'data' => [...$entry, 'level' => 'facility', 'parent_id' => null, 'synthetic_fixture' => true, 'source_page' => 2,
                    'source_url' => $this->catalog->all()['source_url'], 'catalog' => 'lees-mcrae-official-map-v1', 'hierarchy_verified' => false]]);
            $this->save($row, $user, 'fixture.seed', null);
            $count++;
        }

        return ['id' => $input['id'], 'changed' => $count, 'synthetic_fixture' => true];
    }
}
