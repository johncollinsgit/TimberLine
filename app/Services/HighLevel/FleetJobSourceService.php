<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\FleetOperationRecord as Record;
use App\Models\HighLevel\Installation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FleetJobSourceService
{
    public function __construct(private readonly HighLevelApi $api) {}

    public function sources(Installation $install): array
    {
        $calendars = $this->api->get($install, '/calendars/', ['locationId' => $install->location_id, 'showDrafted' => 'false']);
        $pipelines = $this->api->get($install, '/opportunities/pipelines', ['locationId' => $install->location_id]);

        abort_unless(is_array($calendars['calendars'] ?? null) && is_array($pipelines['pipelines'] ?? null), 503, 'CRM returned an incomplete calendar or pipeline list. Saved work is preserved.');

        return ['calendars' => collect($calendars['calendars'] ?? [])->filter(fn ($v) => ($v['locationId'] ?? null) === $install->location_id)
            ->map(fn ($v) => ['id' => $v['id'], 'name' => $v['name'] ?? $v['id']])->values()->all(),
            'pipelines' => collect($pipelines['pipelines'] ?? [])->filter(fn ($v) => ! isset($v['locationId']) || $v['locationId'] === $install->location_id)->map(fn ($v) => ['id' => $v['id'], 'name' => $v['name'] ?? $v['id']])->values()->all()];
    }

    public function sync(Installation $install, string $type, string $sourceId): array
    {
        abort_unless($install->setupAllowed(), 403);
        $sources = $this->sources($install);
        abort_unless(collect($sources[$type === 'calendar' ? 'calendars' : 'pipelines'])->contains('id', $sourceId), 422, 'Choose a source from this CRM account.');
        $rows = $this->readSource($install, $type, $sourceId);
        $safe = $this->normalize($install, $type, $sourceId, $rows);
        $this->saveSnapshot($install, $safe, ['type' => $type, 'id' => $sourceId]);

        return ['saved' => true, 'count' => count($safe)];
    }

    public function syncAutomatic(Installation $install, bool $force = false): array
    {
        abort_unless($install->setupAllowed(), 403);

        return Cache::lock('hl:work-sync:'.$install->id, 180)->block(3, function () use ($install, $force): array {
            $current = Record::forTenantId($install->tenant_id)->where('kind', 'job_source')->first()?->payload;
            if (! $force && ($current['type'] ?? null) === 'automatic' && filled($current['synced_at'] ?? null)
                && CarbonImmutable::parse($current['synced_at'])->gt(now()->subMinutes(5))) {
                return ['saved' => true, 'cached' => true, 'count' => Record::forTenantId($install->tenant_id)->where('kind', 'job')->where('status', 'active')->count()];
            }
            $found = $this->sources($install);
            $sources = [];
            foreach (['calendars' => 'calendar', 'pipelines' => 'pipeline'] as $key => $type) {
                foreach ($found[$key] as $source) {
                    abort_unless(is_string($source['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $source['id']), 503, 'CRM returned an incomplete work list. Try again shortly.');
                    $sources[] = ['type' => $type, 'id' => $source['id'], 'name' => $source['name']];
                }
            }
            abort_if(count($sources) > 50, 422, 'This account has more than 50 calendars and pipelines. Contact support to configure a larger work sync; saved work is preserved.');
            $safe = [];
            foreach ($sources as $source) {
                $rows = $this->readSource($install, $source['type'], $source['id']);
                $safe = array_replace($safe, $this->normalize($install, $source['type'], $source['id'], $rows));
                abort_if(count($safe) > 500, 422, 'This account has more than 500 appointments and open opportunities in the sync window. Contact support to configure a larger work sync; saved work is preserved.');
            }
            // Every source must finish before replacing the snapshot. An outage,
            // missing permission or capacity limit cannot erase saved planning.
            $this->saveSnapshot($install, $safe, ['type' => 'automatic', 'sources' => $sources]);

            return ['saved' => true, 'count' => count(array_filter($safe, fn ($row) => ! in_array($row['crm_status'], ['cancelled', 'invalid', 'noshow'], true)))];
        });
    }

    private function readSource(Installation $install, string $type, string $sourceId): array
    {
        if ($type === 'calendar') {
            $response = $this->api->get($install, '/calendars/events', ['locationId' => $install->location_id, 'calendarId' => $sourceId,
                'startTime' => (string) now()->subDays(7)->getTimestampMs(), 'endTime' => (string) now()->addDays(14)->getTimestampMs()]);
            $rows = $response['events'] ?? null;
            abort_unless(is_array($rows), 503, 'CRM returned an incomplete calendar. Saved work is preserved.');
            abort_if(count($rows) > 500, 422, 'This calendar has more than 500 stops in the sync window. Saved work is preserved.');

            return $rows;
        }
        $rows = [];
        for ($page = 1; $page <= 6; $page++) {
            $response = $this->api->get($install, '/opportunities/search', ['location_id' => $install->location_id, 'pipeline_id' => $sourceId, 'status' => 'open', 'limit' => 100, 'page' => $page]);
            $batch = $response['opportunities'] ?? null;
            abort_unless(is_array($batch), 503, 'CRM returned an incomplete opportunity list. Saved work is preserved.');
            array_push($rows, ...$batch);
            if (count($batch) < 100) {
                break;
            }
        }
        abort_if(count($rows) > 500, 422, 'This pipeline has more than 500 open opportunities. Saved work is preserved.');

        return $rows;
    }

    private function normalize(Installation $install, string $type, string $sourceId, array $rows): array
    {
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
                'title' => substr((string) ($row['title'] ?? $row['name'] ?? 'CRM job'), 0, 180),
                'crm_status' => substr((string) ($row['appointmentStatus'] ?? $row['status'] ?? ''), 0, 50),
                'crm_address' => FleetJobLocationService::address($row['address'] ?? $row['contact'] ?? null),
                'customer_name' => mb_substr(trim((string) ($row['contact']['name'] ?? implode(' ', array_filter([$row['contact']['firstName'] ?? '', $row['contact']['lastName'] ?? ''])))), 0, 180),
                'assigned_user' => substr((string) ($row['assignedUserId'] ?? $row['assignedTo'] ?? ''), 0, 100),
                'crm_start' => $this->date($row['startTime'] ?? null), 'crm_end' => $this->date($row['endTime'] ?? null), 'synced_at' => now()->toIso8601String()];
        }

        return $safe;
    }

    private function saveSnapshot(Installation $install, array $safe, array $source): void
    {
        DB::transaction(function () use ($install, $safe, $source): void {
            Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            abort_unless($install->refresh()->setupAllowed(), 403);
            Record::forTenantId($install->tenant_id)->where('kind', 'job')->update(['status' => 'inactive']);
            foreach ($safe as $key => $p) {
                $record = Record::firstOrNew(['tenant_id' => $install->tenant_id, 'kind' => 'job', 'source_key' => $key]);
                $existing = $record->payload ?? [];
                // A changed imported address invalidates its old point, but a
                // dispatcher's explicit local address remains their choice.
                if (empty($existing['address_override']) && ($existing['crm_address'] ?? null) !== $p['crm_address']) {
                    $existing = array_replace($existing, ['latitude' => null, 'longitude' => null, 'geocoded_at' => null, 'location_provider' => null, 'location_status' => 'pending']);
                }
                $record->fill(['payload' => array_replace($existing, $p), 'event_at' => now(),
                    'status' => in_array($p['crm_status'], ['cancelled', 'invalid', 'noshow'], true) ? 'inactive' : 'active'])->save();
            }
            Record::updateOrCreate(['tenant_id' => $install->tenant_id, 'kind' => 'job_source', 'source_key' => hash('sha256', 'source')],
                ['payload' => [...$source, 'synced_at' => now()->toIso8601String()], 'event_at' => now()]);
        });

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
