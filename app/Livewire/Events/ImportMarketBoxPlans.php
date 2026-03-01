<?php

namespace App\Livewire\Events;

use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportMarketBoxPlans extends Component
{
    use WithFileUploads;

    public $file;
    public ?string $selectedBatchId = null;

    public array $report = [];
    public array $warnings = [];

    public function importCsv(): void
    {
        $this->warnings = [];
        $this->report = [];

        $this->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $path = $this->file->getRealPath();
        if (! $path || ! file_exists($path)) {
            $this->warnings[] = 'Upload failed. Try again.';

            return;
        }

        $rows = [];
        if (($handle = fopen($path, 'r')) !== false) {
            $header = null;
            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(fn ($h) => Str::snake(trim((string) $h)), $data);
                    continue;
                }

                if (count(array_filter($data, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                $row = [];
                foreach ($header as $index => $key) {
                    $row[$key] = $data[$index] ?? null;
                }
                $rows[] = $row;
            }
            fclose($handle);
        }

        $this->report = $this->importRows($rows);
        $this->selectedBatchId = (string) ($this->report['import_batch_id'] ?? '');
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Market box plans import complete.']);
    }

    public function deleteSelectedBatch(): void
    {
        $batchId = trim((string) ($this->selectedBatchId ?? ''));
        if ($batchId === '') {
            return;
        }

        $deletedPlans = 0;
        $deletedInstances = 0;

        DB::transaction(function () use ($batchId, &$deletedPlans, &$deletedInstances): void {
            $deletedPlans = EventBoxPlan::query()
                ->where('import_batch_id', $batchId)
                ->delete();

            $deletedInstances = EventInstance::query()
                ->where('import_batch_id', $batchId)
                ->delete();
        });

        $this->report = [
            'deleted_batch_id' => $batchId,
            'deleted_box_plans' => $deletedPlans,
            'deleted_event_instances' => $deletedInstances,
        ];

        $this->selectedBatchId = null;
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Deleted import batch {$batchId}.",
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,int|string>
     */
    protected function importRows(array $rows): array
    {
        $batchId = (string) Str::uuid();
        $createdInstances = 0;
        $updatedInstances = 0;
        $createdBoxPlans = 0;
        $skippedRows = 0;
        $clearedInstanceIds = [];

        $parseDate = function ($value): ?Carbon {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable $e) {
                return null;
            }
        };

        $decimalOrNull = function ($value): ?float {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }

            return is_numeric($value) ? (float) $value : null;
        };

        foreach ($rows as $row) {
            try {
                $state = Str::upper(substr(trim((string) ($row['event_state'] ?? $row['state'] ?? '')), 0, 2));
                $title = EventInstance::formatImportedTitle($row['event_title'] ?? null, $state);
                $startsAt = $parseDate($row['event_starts_at'] ?? $row['starts_at'] ?? null);
                $endsAt = $parseDate($row['event_ends_at'] ?? $row['ends_at'] ?? null);
                $sourceSheet = trim((string) ($row['sheet_title'] ?? $row['source_sheet'] ?? ''));
                $sourceSheet = $sourceSheet !== '' ? $sourceSheet : null;
                $sourceFile = trim((string) ($row['source_file'] ?? ''));
                $sourceFile = $sourceFile !== '' ? $sourceFile : null;
                $scentRaw = trim((string) ($row['scent_raw'] ?? $row['scent'] ?? ''));
                $lineNotes = trim((string) ($row['line_notes'] ?? ''));
                $lineNotes = $lineNotes !== '' ? $lineNotes : null;
                $boxCountSent = $decimalOrNull($row['box_count_sent'] ?? null);
                $boxCountReturned = $decimalOrNull($row['box_count_returned'] ?? null);
                $isSplitBox = filter_var($row['is_split_box'] ?? (str_contains($scentRaw, '/') ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);

                if ($title === '') {
                    $skippedRows++;
                    continue;
                }

                if (str_contains(Str::lower($scentRaw), 'total')) {
                    $skippedRows++;
                    continue;
                }

                if ($scentRaw === '' || ($boxCountSent === null && $boxCountReturned === null && $lineNotes === null)) {
                    $skippedRows++;
                    continue;
                }

                $instanceQuery = EventInstance::query()
                    ->where('title', $title);

                if ($startsAt) {
                    $instanceQuery->whereDate('starts_at', $startsAt->toDateString());
                } else {
                    $instanceQuery->whereNull('starts_at');
                }

                if ($sourceSheet) {
                    $instanceQuery->where('source_sheet', $sourceSheet);
                } else {
                    $instanceQuery->whereNull('source_sheet');
                }

                $instance = $instanceQuery->first();

                $payload = [
                    'title' => $title,
                    'venue' => $this->nullIfBlank($row['venue'] ?? null),
                    'city' => $this->nullIfBlank($row['city'] ?? null),
                    'state' => $state !== '' ? $state : null,
                    'starts_at' => $startsAt?->toDateString(),
                    'ends_at' => $endsAt?->toDateString(),
                    'status' => $this->sanitizeStatus($row['event_status'] ?? $row['status'] ?? null),
                    'notes' => $this->nullIfBlank($row['event_notes_raw'] ?? $row['notes'] ?? null),
                    'primary_runner' => $this->nullIfBlank($row['primary_runner'] ?? $row['runner'] ?? null),
                    'days_attended' => $this->integerOrNull($row['days_attended'] ?? null),
                    'selling_hours' => $decimalOrNull($row['selling_hours'] ?? null),
                    'total_sales' => $decimalOrNull($row['total_sales'] ?? null),
                    'boxes_sold' => $decimalOrNull($row['boxes_sold'] ?? null),
                    'source_file' => $sourceFile,
                    'source_sheet' => $sourceSheet,
                    'import_batch_id' => $batchId,
                ];

                if ($instance) {
                    $instance->fill($payload)->save();
                    $updatedInstances++;
                } else {
                    $instance = EventInstance::query()->create($payload);
                    $createdInstances++;
                }

                $instanceId = (int) $instance->id;
                if (! in_array($instanceId, $clearedInstanceIds, true)) {
                    EventBoxPlan::query()
                        ->where('event_instance_id', $instanceId)
                        ->delete();
                    $clearedInstanceIds[] = $instanceId;
                }

                EventBoxPlan::query()->create([
                    'event_instance_id' => $instanceId,
                    'scent_raw' => $scentRaw,
                    'box_count_sent' => $boxCountSent,
                    'box_count_returned' => $boxCountReturned,
                    'line_notes' => $lineNotes,
                    'is_split_box' => $isSplitBox,
                    'import_batch_id' => $batchId,
                ]);
                $createdBoxPlans++;
            } catch (\Throwable $e) {
                Log::warning('market box plans import row failed', [
                    'row' => $row,
                    'error' => $e->getMessage(),
                ]);
                $skippedRows++;
            }
        }

        return [
            'import_batch_id' => $batchId,
            'event_instances_created' => $createdInstances,
            'event_instances_updated' => $updatedInstances,
            'box_plans_created' => $createdBoxPlans,
            'skipped' => $skippedRows,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function recentBatches(): array
    {
        return EventInstance::query()
            ->selectRaw('import_batch_id, max(updated_at) as latest_updated_at')
            ->whereNotNull('import_batch_id')
            ->groupBy('import_batch_id')
            ->orderByDesc('latest_updated_at')
            ->limit(10)
            ->get()
            ->map(function (EventInstance $instance): array {
                $batchId = (string) $instance->import_batch_id;
                $instanceCount = EventInstance::query()->where('import_batch_id', $batchId)->count();
                $boxPlanCount = EventBoxPlan::query()->where('import_batch_id', $batchId)->count();

                return [
                    'batch_id' => $batchId,
                    'event_instances' => $instanceCount,
                    'box_plans' => $boxPlanCount,
                ];
            })
            ->all();
    }

    protected function sanitizeStatus(mixed $value): string
    {
        $status = Str::lower(trim((string) $value));

        return in_array($status, ['planned', 'active', 'completed', 'unknown'], true) ? $status : 'unknown';
    }

    protected function integerOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    protected function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public function render()
    {
        return view('livewire.events.import-market-box-plans', [
            'recentBatches' => $this->recentBatches(),
        ])->layout('layouts.app');
    }
}
