<?php

namespace App\Services\Team\Studio;

use App\Models\MarketingImportRun;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Team\Operations\OperationsAccess;

/** Suggestions for an explicit manager review, never an inferred event allocation. */
class EventSalesMappingCandidates
{
    public function __construct(private OperationsAccess $access) {}

    public function read(Tenant $tenant, User $user, TeamOperationRecord $event): array
    {
        $this->access->financial($tenant, $user);
        $this->access->record($tenant, $user, $event);
        abort_unless($event->kind === 'event', 404);
        $area = \App\Models\TeamArea::where('tenant_id', $tenant->id)->findOrFail($event->area_id);

        return $this->access->readCollection($tenant, $user, $area, function () use ($tenant, $user, $event): array {
            $scope = ['tenant_id' => $tenant->id, 'area_id' => $event->area_id];
            $maps = TeamOperationRecord::where($scope)->where(['kind' => 'event_link', 'status' => 'accepted'])->get();
            $mapping = $maps->first(fn ($row) => ($row->data['occurrence_id'] ?? null) === $event->id);
            $series = [];
            $aliases = [$this->normalize($event->title)];
            $review = [];
            foreach (TeamOperationRecord::where($scope)->where('kind', 'series')->where('status', '!=', 'withdrawn')->orderBy('title')->limit(201)->get() as $record) {
                try {
                    $this->access->record($tenant, $user, $record);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    $review[] = 'Some source records are unavailable with current access.';

                    continue;
                }
                $series[] = ['id' => $record->id, 'name' => $record->title, 'version' => $record->version];
                if (($mapping?->data['series_id'] ?? null) === $record->id) {
                    $aliases = [...$aliases, $this->normalize($record->title), ...array_map($this->normalize(...), array_slice((array) ($record->data['alias_names'] ?? []), 0, 20))];
                }
            }
            $aliases = array_values(array_unique(array_filter($aliases)));
            $query = TeamOperationRecord::where($scope)->whereIn('kind', ['event', 'financial'])->where('status', '!=', 'withdrawn');
            $query->where(function ($query) use ($aliases): void {
                foreach ($aliases as $alias) {
                    $parts = preg_split('/\s+/', $alias);
                    $query->orWhere('title', 'like', '%'.implode('%', array_map(fn ($word) => addcslashes($word, '%_\\'), $parts)).'%');
                }
            });
            $rows = $query->orderByDesc('starts_on')->orderBy('id')->limit(501)->get();
            $candidates = [];
            foreach ($rows->take(500) as $record) {
                if (! in_array($this->normalize($record->title), $aliases, true)) {
                    continue;
                }
                try {
                    $this->access->record($tenant, $user, $record);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    $review[] = 'Some source records are unavailable with current access.';

                    continue;
                }
                $data = $record->data;
                $link = $maps->first(fn ($row) => ($row->data['occurrence_id'] ?? null) === $record->id);
                $candidates[] = ['id' => $record->id, 'kind' => $record->kind, 'title' => $record->title, 'version' => $record->version,
                    'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(),
                    'person_name' => $data['person_name'] ?? $data['source_assignee_name'] ?? null,
                    'reported_net_sales_minor' => $data['net_sales_cents'] ?? null,
                    'source_tab' => $data['source_tab'] ?? null, 'source_row' => $data['source_row'] ?? null,
                    'needs_allocation_review' => (bool) ($data['needs_allocation_review'] ?? false),
                    'mapping_id' => $link?->id, 'series_id' => $link?->data['series_id'] ?? null,
                    'mapping' => $link ? ['id' => $link->id, 'version' => $link->version, 'data' => $link->data] : null];
            }
            $locations = [];
            $staffChoices = [];
            foreach (TeamOperationRecord::where($scope)->where('kind', 'staff')->where('status', '!=', 'withdrawn')->orderBy('title')->limit(201)->get() as $person) {
                try {
                    $this->access->record($tenant, $user, $person);
                    $staffChoices[] = ['id' => $person->id, 'name' => $person->title, 'version' => $person->version,
                        'person_id' => $person->data['person_id'] ?? null, 'identity_review_required' => (bool) ($person->data['identity_review'] ?? false)];
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    $review[] = 'Some source staffing records need current access review.';
                }
            }
            $policy = TenantMarketingSetting::where(['tenant_id' => $tenant->id, 'key' => 'square_source_refresh'])->first()?->value ?? [];
            foreach ($policy['discovered_locations'] ?? [] as $location) {
                if (is_string($location['id'] ?? null)) {
                    $locations[$location['id']] = ['id' => $location['id'], 'name' => $location['name'] ?? $location['id'], 'status' => $location['status'] ?? null, 'review_required' => true];
                }
            }
            foreach (app(SquareConnectionContext::class)->available($tenant->id) as $binding) {
                foreach ($binding['location_ids'] ?? array_filter([$binding['location_id'] ?? null]) as $id) {
                    $locations[$id] ??= ['id' => $id, 'name' => $binding['location_label'] ?? $id, 'status' => null, 'review_required' => true];
                }
            }
            $runs = [];
            foreach (MarketingImportRun::forTenantId($tenant->id)->whereIn('type', ['square_orders_sync', 'square_payments_sync'])->where('status', 'completed')->orderByDesc('id')->limit(100)->get() as $run) {
                $summary = $run->summary ?? [];
                if (($summary['mode'] ?? null) !== 'live-sync' || (int) ($summary['errors'] ?? 0) !== 0 || data_get($summary, 'checkpoint.cursor')) {
                    continue;
                }
                $runs[] = ['id' => $run->id, 'type' => $run->type, 'location_ids' => data_get($summary, 'source_binding.location_ids', []),
                    'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
                    'status' => $run->status, 'from' => $summary['since'] ?? null, 'to' => $run->started_at?->toIso8601String(),
                    'coverage_complete' => false];
            }

            return ['event_id' => $event->id, 'event_version' => $event->version, 'area_id' => $event->area_id,
                'mapping' => $mapping ? ['id' => $mapping->id, 'version' => $mapping->version, 'data' => $mapping->data] : null,
                'series' => array_slice($series, 0, 200), 'candidates' => $candidates, 'staff_choices' => array_slice($staffChoices, 0, 200), 'square_locations' => array_values($locations), 'source_runs' => $runs,
                'truncated' => $rows->count() > 500 || count($series) > 200 || count($staffChoices) > 200, 'review_items' => array_values(array_unique($review)),
                'basis' => 'Matching titles and reviewed aliases suggest records only. Review venue, dates, staff, repeated totals and original Square coverage before saving. No source record or allocation was changed.'];
        }, true);
    }

    private function normalize(string $title): string
    {
        $title = preg_replace('/\b\d{1,2}[.\/-]\d{1,2}[.\/-](?:\d{4}|\d{2})\b/u', ' ', $title);
        $title = preg_replace('/\b(?:19|20)\d{2}\b/u', ' ', $title);

        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($title)));
    }
}
