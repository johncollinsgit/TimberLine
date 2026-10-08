<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\FleetOperationRecord as Record;
use App\Models\HighLevel\Installation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FleetJobSourceService
{
    public function __construct(private readonly HighLevelApi $api) {}

    public function sources(Installation $install): array
    {
        $calendars = $this->api->get($install, '/calendars/', ['locationId' => $install->location_id, 'showDrafted' => 'false']);
        $pipelines = $this->api->get($install, '/opportunities/pipelines', ['locationId' => $install->location_id]);

        return ['calendars' => collect($calendars['calendars'] ?? [])->filter(fn ($v) => ($v['locationId'] ?? null) === $install->location_id)
            ->map(fn ($v) => ['id' => $v['id'], 'name' => $v['name'] ?? $v['id']])->values()->all(),
            'pipelines' => collect($pipelines['pipelines'] ?? [])->map(fn ($v) => ['id' => $v['id'], 'name' => $v['name'] ?? $v['id']])->values()->all()];
    }

    public function sync(Installation $install, string $type, string $sourceId): array
    {
        abort_unless($install->setupAllowed(), 403);
        $sources = $this->sources($install);
        abort_unless(collect($sources[$type === 'calendar' ? 'calendars' : 'pipelines'])->contains('id', $sourceId), 422, 'Choose a source from this HighLevel account.');
        if ($type === 'calendar') {
            $response = $this->api->get($install, '/calendars/events', ['locationId' => $install->location_id, 'calendarId' => $sourceId,
                'startTime' => (string) now()->subDays(7)->getTimestampMs(), 'endTime' => (string) now()->addDays(14)->getTimestampMs()]);
            $rows = $response['events'] ?? [];
            abort_unless(is_array($rows) && count($rows) <= 500, 422, 'Use a smaller calendar with up to 500 stops in this window.');
        } else {
            $rows = [];
            for ($page = 1; $page <= 6; $page++) {
                $response = $this->api->get($install, '/opportunities/search', ['location_id' => $install->location_id, 'pipeline_id' => $sourceId, 'status' => 'open', 'limit' => 100, 'page' => $page]);
                $batch = $response['opportunities'] ?? [];
                abort_unless(is_array($batch), 503);
                array_push($rows, ...$batch);
                if (count($batch) < 100) {
                    break;
                }
            }
            abort_unless(count($rows) <= 500, 422, 'Use a pipeline with up to 500 open opportunities.');
        }
        // Complete the read before replacing the source snapshot. Failed reads
        // preserve saved references; no HighLevel writes or Everbranch jobs.
        $safe = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $row['id'] ?? '')) {
                continue;
            }
            abort_if(isset($row['locationId']) && $row['locationId'] !== $install->location_id, 403);
            if ($type === 'calendar' && ($row['calendarId'] ?? null) !== $sourceId) {
                continue;
            }
            if ($type === 'pipeline' && ($row['pipelineId'] ?? null) !== $sourceId) {
                continue;
            }
            $key = hash('sha256', $type.'|'.$row['id']);
            $safe[$key] = ['source' => $type, 'source_id' => $sourceId, 'reference' => $row['id'],
                'title' => substr((string) ($row['title'] ?? $row['name'] ?? 'HighLevel job'), 0, 180),
                'crm_status' => substr((string) ($row['appointmentStatus'] ?? $row['status'] ?? ''), 0, 50),
                'assigned_user' => substr((string) ($row['assignedUserId'] ?? $row['assignedTo'] ?? ''), 0, 100),
                'crm_start' => $this->date($row['startTime'] ?? null), 'crm_end' => $this->date($row['endTime'] ?? null), 'synced_at' => now()->toIso8601String()];
        }
        DB::transaction(function () use ($install, $safe, $type, $sourceId): void {
            Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            abort_unless($install->refresh()->setupAllowed(), 403);
            Record::forTenantId($install->tenant_id)->where('kind', 'job')->update(['status' => 'inactive']);
            foreach ($safe as $key => $p) {
                $record = Record::firstOrNew(['tenant_id' => $install->tenant_id, 'kind' => 'job', 'source_key' => $key]);
                $record->fill(['payload' => array_replace($record->payload ?? [], $p), 'event_at' => now(),
                    'status' => in_array($p['crm_status'], ['cancelled', 'invalid', 'noshow'], true) ? 'inactive' : 'active'])->save();
            }
            Record::updateOrCreate(['tenant_id' => $install->tenant_id, 'kind' => 'job_source', 'source_key' => hash('sha256', 'source')],
                ['payload' => ['type' => $type, 'id' => $sourceId, 'synced_at' => now()->toIso8601String()], 'event_at' => now()]);
        });

        return ['saved' => true, 'count' => count($safe)];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
