<?php

namespace App\Services\Team\Studio;

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Freshly witnessed source projections, never an access grant or a cross-request cache. */
final class EventSquareAllocationFacts
{
    private array $snapshots = [];

    private ?array $recordColumns = null;

    /** Every reuse first checks the complete current row/ciphertext inventory. */
    public function occurrencesFor(int $tenant, string $orderId): \Illuminate\Support\Collection
    {
        $facts = $this->facts($tenant);
        $models = $this->snapshots[$tenant]['models'] ?? [];
        $result = [];
        foreach ($facts as $id => $fact) {
            if (in_array($orderId, $fact['order_ids'], true) && isset($models[$id])) {
                // Callers may decorate their copy; that must not alter the witness.
                $result[] = clone $models[$id];
            }
        }

        return collect($result);
    }

    /** Candidate IDs only; callers still freshly load and admit the original records. */
    public function occurrenceIdsFor(int $tenant, array $orderIds): array
    {
        $requested = array_fill_keys($this->strings($orderIds), true);
        $result = [];
        foreach ($this->facts($tenant) as $id => $fact) {
            foreach ($fact['order_ids'] as $order) {
                if (isset($requested[$order])) {
                    $result[$order][] = $id;
                }
            }
        }
        foreach ($result as &$ids) {
            sort($ids, SORT_STRING);
        }
        unset($ids);
        ksort($result);

        return $result;
    }

    /** The paid-source reader rejects every other retained occurrence claiming an order. */
    public function conflictsFor(int $tenant, array $orderIds, string $occurrence): array
    {
        return $this->conflicts($this->facts($tenant), $orderIds, $occurrence);
    }

    /** Revision and conflict answers must describe the same captured projection. */
    public function conflictFrameFor(int $tenant, array $orderIds, string $occurrence): array
    {
        $facts = $this->facts($tenant);

        return ['revision' => $this->effectiveRevision($tenant, $orderIds, [], $facts),
            'conflicts' => $this->conflicts($facts, $orderIds, $occurrence)];
    }

    private function conflicts(array $facts, array $orderIds, string $occurrence): array
    {
        $requested = array_fill_keys($this->strings($orderIds), true);
        $conflicts = [];
        foreach ($facts as $id => $fact) {
            if ($id === $occurrence) {
                continue;
            }
            foreach ($fact['order_ids'] as $order) {
                if (isset($requested[$order])) {
                    $conflicts[$order] = true;
                }
            }
        }
        $ids = array_map('strval', array_keys($conflicts));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** Fresh effective selectors across areas, excluding unrelated observation/notes changes. */
    public function revisionFor(int $tenant, array $squareOrderIds, array $commerceEventIds = []): string
    {
        return $this->effectiveRevision($tenant, $squareOrderIds, $commerceEventIds, $this->facts($tenant));
    }

    private function effectiveRevision(int $tenant, array $squareOrderIds, array $commerceEventIds, array $facts): string
    {
        $orders = $this->strings($squareOrderIds);
        $events = array_values(array_unique(array_map('intval', array_filter($commerceEventIds, fn ($id) => (is_int($id) || is_string($id) && ctype_digit($id)) && (int) $id > 0))));
        sort($events, SORT_NUMERIC);
        $eventSet = array_fill_keys($events, true);
        $selected = [];
        $sourceIds = [];
        $explicit = [];
        foreach ($facts as $id => $fact) {
            if ($orders !== [] && $fact['order_ids'] !== [] && array_intersect($orders, $fact['order_ids'])) {
                $selected[$id] = true;
                $sourceIds[] = $fact['source']['source_id'] ?? '';
                $explicit[] = $fact['commerce_id'];
            }
        }
        $explicit = array_values(array_unique(array_filter([...$events, ...$explicit])));
        $sourceIds = $this->strings($sourceIds);
        $links = [];
        if (Schema::hasTable('commerce_stock_event_links') && ($explicit || $sourceIds)) {
            $links = DB::table('commerce_stock_event_links')->where('tenant_id', $tenant)->whereNotNull('reviewed_by')
                ->where(fn ($query) => $query->whereIn('event_id', $explicit)->orWhere(fn ($query) => $query->where('provider', 'asana')->whereIn('external_id', $sourceIds)))
                ->orderBy('id')->get(['id', 'event_id', 'provider', 'external_id', 'reviewed_by', 'metadata'])->map(fn ($row) => (array) $row)->all();
        }
        $requestedAsana = [];
        foreach ($links as $link) {
            if ($link['provider'] === 'asana' && isset($eventSet[(int) $link['event_id']])) {
                $requestedAsana[(string) $link['external_id']] = true;
            }
        }
        $candidates = array_filter($facts, fn ($fact, $id) => isset($selected[$id]) || isset($eventSet[$fact['commerce_id']]) || isset($requestedAsana[$fact['source']['source_id'] ?? '']), ARRAY_FILTER_USE_BOTH);
        $connectionIds = array_values(array_unique(array_filter(array_column($candidates, 'connection_id'))));
        $connections = [];
        foreach (TeamProviderConnection::where('tenant_id', $tenant)->whereIn('id', $connectionIds)->orderBy('id')->get(['id', 'provider', 'user_id', 'generation', 'status', 'settings', 'expires_at', 'account_key']) as $connection) {
            $settings = $connection->settings ?? [];
            $connections[$connection->id] = ['id' => $connection->id, 'provider' => $connection->provider, 'user_id' => $connection->user_id,
                'generation' => $connection->generation, 'status' => $connection->status, 'account_key' => $connection->account_key,
                'not_expired' => ! $connection->expires_at || $connection->expires_at->isFuture(),
                'source' => $settings['source'] ?? [], 'read_scopes' => $settings['scopes'] ?? null, 'external_user_id' => $settings['external_user_id'] ?? null,
                'selection_required' => $settings['selection_required'] ?? null, 'mapping_approved' => $settings['mapping_approved'] ?? null];
        }
        $relevant = [];
        $reviewers = [...array_column($links, 'reviewed_by'), ...array_column($connections, 'user_id')];
        foreach ($candidates as $id => $fact) {
            $ownEvent = isset($eventSet[$fact['commerce_id']]) || ! $fact['commerce_explicit'] && isset($requestedAsana[$fact['source']['source_id'] ?? '']) && ($connections[$fact['connection_id']]['provider'] ?? null) === 'asana';
            if (! isset($selected[$id]) && ! $ownEvent) {
                continue;
            }
            $fact['order_ids'] = $ownEvent ? $fact['order_ids'] : array_values(array_intersect($fact['order_ids'], $orders));
            $relevant[$id] = $fact;
            $reviewers[] = $fact['review']['reviewed_by'] ?? null;
        }
        $usedConnections = array_fill_keys(array_filter(array_column($relevant, 'connection_id')), true);
        $connections = array_intersect_key($connections, $usedConnections);
        $reviewers = array_values(array_unique(array_filter($reviewers, fn ($id) => (is_int($id) || is_string($id) && ctype_digit($id)) && (int) $id > 0)));
        $users = DB::table('users')->whereIn('id', $reviewers)->orderBy('id')->get(['id', 'role', 'is_active', 'email_verified_at'])->map(fn ($row) => (array) $row)->all();
        $memberships = DB::table('tenant_user')->where('tenant_id', $tenant)->whereIn('user_id', $reviewers)->orderBy('user_id')->get(['user_id', 'role', 'membership_active'])->map(fn ($row) => (array) $row)->all();

        return hash('sha256', json_encode(['allocation-facts-v1', $tenant, (string) config('pos_live.runtime_id', ''), $orders, $events, $relevant, $connections, $links, $users, $memberships], JSON_THROW_ON_ERROR));
    }

    /** Metadata hashes fence both normal versioned writes and direct same-version edits. */
    private function facts(int $tenant): array
    {
        abort_unless($tenant > 0, 404);
        $before = $this->metadata($tenant);
        if ($before === ($this->snapshots[$tenant]['metadata'] ?? [])) {
            return $this->snapshots[$tenant]['facts'] ?? [];
        }
        $db = DB::connection();
        if ($db->getDriverName() === 'mysql' && $db->transactionLevel() === 0 && ! $db->getPdo()->inTransaction()) {
            // Only a changed projection needs a coherent read view. Keep every
            // hot reuse's fresh byte witness, and never carry the pre-frame
            // metadata into a newer snapshot of the encrypted bodies.
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            try {
                return $db->transaction(fn () => $this->reloadFacts($tenant));
            } catch (\Throwable $error) {
                unset($this->snapshots[$tenant]);
                throw $error;
            }
        }

        // A caller owns its existing transaction and isolation. Preserve the
        // conservative mixed-read retry boundary rather than changing either.
        return $this->reloadFacts($tenant, $before);
    }

    private function reloadFacts(int $tenant, ?array $firstMetadata = null): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $attempt === 0 && $firstMetadata !== null ? $firstMetadata : $this->metadata($tenant);
            $cached = $this->snapshots[$tenant] ?? ['metadata' => [], 'facts' => [], 'models' => []];
            if ($before === $cached['metadata']) {
                return $cached['facts'];
            }
            $facts = array_intersect_key($cached['facts'], $before);
            $models = array_intersect_key($cached['models'], $before);
            $changed = array_keys(array_filter($before, fn ($value, $id) => ($cached['metadata'][$id] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            // A changed row that disappears during reload cannot retain its old facts.
            $facts = array_diff_key($facts, array_fill_keys($changed, true));
            $models = array_diff_key($models, array_fill_keys($changed, true));
            $coherent = true;
            foreach (array_chunk($changed, 500) as $ids) {
                foreach ($this->rows($tenant)->whereIn('id', $ids)->get() as $row) {
                    if (($before[$row->id] ?? null) !== $this->rowMetadata($row)) {
                        $coherent = false;
                        break 2;
                    }
                    $attributes = (array) $row;
                    unset($attributes['ciphertext_hash']);
                    $record = (new TeamOperationRecord)->newFromBuilder($attributes);
                    $models[$record->id] = $record;
                    $data = $record->data ?? [];
                    $review = $data['_teams_enrichment']['square_orders'] ?? [];
                    abort_unless(is_array($review) && (! isset($review['ids']) || is_array($review['ids'])), 409, 'Event allocation metadata needs review.');
                    foreach ($review['ids'] ?? [] as $orderId) {
                        abort_unless(is_string($orderId) || is_int($orderId), 409, 'Event allocation metadata needs review.');
                    }
                    $commerce = $data['_teams_enrichment']['commerce_event']['id'] ?? null;
                    $facts[$record->id] = ['id' => $record->id, 'area_id' => $record->area_id, 'status' => $record->status, 'connection_id' => $record->connection_id, 'assigned_user_id' => $record->assigned_user_id, 'visibility' => $record->visibility,
                        'order_ids' => $this->strings(is_array($review['ids'] ?? null) ? $review['ids'] : []),
                        'review' => array_intersect_key($review, array_flip(['reviewed_by', 'reviewed_at'])) + (array_key_exists('source_binding', $review) ? ['source_binding_sha256' => hash('sha256', json_encode($review['source_binding'], JSON_THROW_ON_ERROR))] : []),
                        'commerce_explicit' => (bool) $commerce, 'commerce_selector_sha256' => hash('sha256', json_encode($commerce, JSON_THROW_ON_ERROR)),
                        'commerce_id' => (is_int($commerce) || is_string($commerce) && ctype_digit($commerce)) && (int) $commerce > 0 ? (int) $commerce : null,
                        'source' => array_intersect_key($data, array_flip(['source_id', 'source_type', 'source_workspace', 'source_project', 'source_projects', '_generation']))];
                }
            }
            // One statement binds every fetched ciphertext to its observed hash.
            // A second collection detects additions/deletions or a mixed read,
            // without relying on a session's transaction-isolation default.
            if ($coherent && count($facts) === count($before) && $before === $this->metadata($tenant)) {
                ksort($facts);
                $this->snapshots[$tenant] = ['metadata' => $before, 'facts' => $facts, 'models' => $models];

                return $facts;
            }
        }
        abort(409, 'Event allocations changed while reporting was read.');
    }

    private function metadata(int $tenant): array
    {
        $result = [];
        $query = $this->rows($tenant, false);
        foreach ($query->get() as $row) {
            $result[$row->id] = $this->rowMetadata($row);
        }
        ksort($result);

        return $result;
    }

    private function rows(int $tenant, bool $data = true): \Illuminate\Database\Query\Builder
    {
        $identity = ['id', 'area_id', 'version', 'status', 'connection_id', 'assigned_user_id', 'visibility'];
        $this->recordColumns ??= Schema::getColumnListing('team_operation_records');
        $columns = [...$identity, ...array_diff($this->recordColumns, [...$identity, 'data'])];
        if ($data || DB::connection()->getDriverName() !== 'mysql') {
            $columns[] = 'data';
        }
        $query = DB::table('team_operation_records')->where(['tenant_id' => $tenant, 'kind' => 'event'])->where('status', '!=', 'withdrawn')->select($columns);
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->selectRaw('SHA2(`data`,256) as ciphertext_hash');
        }

        return $query;
    }

    private function rowMetadata(object $row): array
    {
        $metadata = (array) $row;
        $hash = array_key_exists('ciphertext_hash', $metadata) ? $metadata['ciphertext_hash'] : hash('sha256', json_encode($metadata['data'] ?? null, JSON_THROW_ON_ERROR));
        unset($metadata['data'], $metadata['ciphertext_hash']);
        ksort($metadata);

        return [$metadata, $hash];
    }

    private function strings(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, fn ($id) => (is_string($id) || is_int($id)) && (string) $id !== ''))));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
