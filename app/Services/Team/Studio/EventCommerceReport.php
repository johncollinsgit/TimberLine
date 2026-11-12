<?php

namespace App\Services\Team\Studio;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosEventSales;
use App\Services\Pos\PosException;
use App\Services\Team\Operations\OperationsAccess;

/** Both apps read this projection. Pay and inventory retain their own transaction authorities. */
class EventCommerceReport
{
    public function __construct(private StockLedger $stock, private OperationsAccess $access) {}

    public function commerceId(TeamOperationRecord $event): ?int
    {
        $data = $event->data;
        $explicit = $data['_teams_enrichment']['commerce_event']['id'] ?? null;
        if ($explicit) {
            return $this->stock->rows('event_links', $event->tenant_id)->where('event_id', $explicit)->whereNotNull('reviewed_by')->exists() ? (int) $explicit : null;
        }
        if (! $event->connection_id || ! isset($data['source_id'])) {
            return null;
        }
        $connection = \App\Models\TeamProviderConnection::where('tenant_id', $event->tenant_id)->find($event->connection_id);
        if ($connection?->provider !== 'asana') {
            return null;
        }
        $ids = $this->stock->rows('event_links', $event->tenant_id)->where('provider', 'asana')->where('external_id', $data['source_id'])->whereNotNull('reviewed_by')->distinct()->pluck('event_id');

        return $ids->count() === 1 ? (int) $ids[0] : null;
    }

    public function forEvent(Tenant $tenant, User $user, TeamOperationRecord $event, bool $includeReceipts = false): array
    {
        $this->access->financial($tenant, $user);
        $this->access->record($tenant, $user, $event);
        abort_unless($event->kind === 'event', 404);
        $id = $this->commerceId($event);
        abort_unless($id, 422, 'Link this occurrence to its reviewed inventory/Pay event first.');
        $canonical = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->whereKeyNot($event->id)->where('status', '!=', 'withdrawn')->get()->first(fn ($other) => ($other->data['_teams_enrichment']['commerce_event']['id'] ?? null) === $id);
        abort_if($canonical, 409, 'Use the reviewed canonical occurrence for this event’s shared profitability.');
        $link = $this->stock->rows('event_links', $tenant->id)->where('event_id', $id)->whereNotNull('reviewed_by')->firstOrFail();
        $metadata = json_decode($link->metadata, true, 64, JSON_THROW_ON_ERROR);
        $timezone = $metadata['timezone'] ?? null;
        abort_unless(in_array($timezone, timezone_identifiers_list(), true), 422, 'Review the shared event timezone.');
        $sales = app(PosEventSales::class)->read($tenant->id, $id, $timezone, $includeReceipts);
        $names = User::whereIn('id', array_column($sales['operators'], 'user_id'))->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenant->id))->pluck('name', 'id');
        $sales['operators'] = array_map(fn ($operator) => $operator + ['name' => $names[$operator['user_id']] ?? null], $sales['operators']);
        $pointer = $event->data['_teams_enrichment']['budget_id'] ?? $event->data['budget_id'] ?? null;
        $budget = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'event_budget', 'status' => 'frozen'])->find($pointer ?? '');
        abort_if($budget && ($budget->data['event_id'] ?? null) !== $event->id, 409, 'The event budget reference requires review.');
        $profitability = $budget && ($budget->data['policy_snapshot']['currency'] ?? null) === 'USD'
            ? app(EventProfitability::class)->calculate($budget->data['inputs'], $budget->data['policy_snapshot'], $sales['net_sales_minor']) : null;
        $history = app(EventIntelligence::class)->detail($tenant, $user, $event);
        $travel = ['planned_millimiles' => 0, 'reported_actual_millimiles' => 0, 'unknown_legs' => 0];
        foreach ($budget?->data['inputs']['trips'] ?? [] as $trip) {
            if (! isset($trip['distance_millimiles'])) {
                $travel['unknown_legs']++;

                continue;
            }
            $key = ($trip['distance_basis'] ?? 'planned') === 'reported_actual' ? 'reported_actual_millimiles' : 'planned_millimiles';
            $travel[$key] += $trip['distance_millimiles'] * ($trip['round_trip'] ? 2 : 1) * $trip['vehicles'];
        }

        return ['contract_version' => 1, 'event_id' => $id, 'teams_event_id' => $event->id, 'event_name' => $event->title,
            'timezone' => $timezone, 'sales' => $sales, 'profitability' => $profitability, 'travel' => $travel,
            'budget' => $budget ? ['id' => $budget->id, 'policy_id' => $budget->data['policy_id'], 'policy_version' => $budget->data['policy_version'], 'frozen_at' => $budget->data['frozen_at']] : null,
            'annual_history' => $history['annual_history'], 'history' => $history['history'],
            'review_items' => $budget ? [] : ['Save an event budget to calculate estimated profitability and break-even.'],
            'basis' => 'Confirmed event-linked Pay, cash and reviewed Square revenue with saved event cost estimates. Recorded workbook comparisons remain separate; they are not added to current sales. Tax uses the register’s reviewed location, not cost settings.'];
    }

    public function forRegister(array $scope, User $user): array
    {
        if (! in_array($scope['role'] ?? null, ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('event_financial_access_required', 403);
        }
        $settings = app(\App\Services\Pos\StockPosCommerce::class)->settings($scope);
        $pool = $this->stock->rows('pools', $scope['tenant_id'])->where('id', $settings['inventory_pool_id'] ?? '')->first();
        if (! $pool || ! preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $match)) {
            throw new PosException('event_register_required', 422);
        }
        $tenant = Tenant::findOrFail($scope['tenant_id']);
        $candidate = $this->candidate($tenant->id, (int) $match[1]);
        if (! $candidate) {
            throw new PosException('event_cost_mapping_required', 422);
        }

        return $this->forEvent($tenant, $user, $candidate);
    }

    public function candidate(int $tenantId, int $eventId): ?TeamOperationRecord
    {
        $ids = $this->lookup($tenantId)['commerce'][$eventId] ?? [];
        if (! $ids) {
            return null;
        }
        $candidates = TeamOperationRecord::where(['tenant_id' => $tenantId, 'kind' => 'event'])->where('status', '!=', 'withdrawn')->whereIn('id', $ids)->get()
            ->filter(fn ($event) => $this->commerceId($event) === $eventId);
        $explicit = $candidates->filter(fn ($event) => isset($event->data['_teams_enrichment']['commerce_event']['id']));
        if ($explicit->isNotEmpty()) {
            $candidates = $explicit;
        }

        return $candidates->count() === 1 ? $candidates->first() : null;

    }

    /** Candidate IDs only; fresh source review, actor and event ownership remain the caller's authority. */
    public function squareOccurrences(int $tenantId, string $orderId): \Illuminate\Support\Collection
    {
        $ids = $this->lookup($tenantId)['square'][$orderId] ?? [];
        if (! $ids) {
            return collect();
        }

        return TeamOperationRecord::where(['tenant_id' => $tenantId, 'kind' => 'event'])->where('status', '!=', 'withdrawn')->whereIn('id', $ids)->get()
            ->filter(fn ($event) => in_array($orderId, $event->data['_teams_enrichment']['square_orders']['ids'] ?? [], true));
    }

    /**
     * One encrypted scan per actual HTTP request, shared across its profiles/purchases.
     * Store IDs only. Console/queue callers rebuild instead of retaining state between jobs.
     */
    private function lookup(int $tenant): array
    {
        if ($tenant < 1) {
            return ['commerce' => [], 'square' => []];
        }
        $request = app('request');
        $memoize = $request instanceof \Illuminate\Http\Request && $request->route() instanceof \Illuminate\Routing\Route;
        $key = 'everbranch.event-purchase-lookup.v1:'.hash('sha256', (string) config('pos_live.runtime_id')).':'.$tenant;
        if ($memoize && is_array($cached = $request->attributes->get($key))) {
            return $cached;
        }
        $reviewed = [];
        $asana = [];
        foreach ($this->stock->rows('event_links', $tenant)->whereNotNull('reviewed_by')->get(['event_id', 'provider', 'external_id']) as $link) {
            $reviewed[(int) $link->event_id] = true;
            if ($link->provider === 'asana') {
                $asana[(string) $link->external_id][(int) $link->event_id] = (int) $link->event_id;
            }
        }
        $connections = array_fill_keys(\App\Models\TeamProviderConnection::where(['tenant_id' => $tenant, 'provider' => 'asana'])->pluck('id')->all(), true);
        $lookup = ['commerce' => [], 'square' => []];
        foreach (TeamOperationRecord::where(['tenant_id' => $tenant, 'kind' => 'event'])->where('status', '!=', 'withdrawn')->get(['id', 'connection_id', 'data']) as $record) {
            $data = $record->data;
            $explicit = $data['_teams_enrichment']['commerce_event']['id'] ?? null;
            $commerce = null;
            if ($explicit) {
                if ((is_int($explicit) || (is_string($explicit) && ctype_digit($explicit))) && isset($reviewed[(int) $explicit])) {
                    $commerce = (int) $explicit;
                }
            } elseif (isset($connections[$record->connection_id], $data['source_id'])) {
                $ids = $asana[(string) $data['source_id']] ?? [];
                if (count($ids) === 1) {
                    $commerce = reset($ids);
                }
            }
            if ($commerce !== null) {
                $lookup['commerce'][$commerce][] = $record->id;
            }
            $orders = $data['_teams_enrichment']['square_orders']['ids'] ?? [];
            foreach (is_array($orders) ? $orders : [] as $id) {
                if (is_string($id)) {
                    $lookup['square'][$id][] = $record->id;
                }
            }
        }
        if ($memoize) {
            $request->attributes->set($key, $lookup);
        }

        return $lookup;
    }
}
