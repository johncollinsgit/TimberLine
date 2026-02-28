<?php

namespace App\Livewire\Events;

use App\Models\MarketPlan;
use App\Models\Event;
use App\Models\EventShipment;
use App\Models\Scent;
use App\Models\Size;
use App\Services\EventMatchingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class Import extends Component
{
    use WithFileUploads;

    public $file;
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
        if (!$path || !file_exists($path)) {
            $this->warnings[] = 'Upload failed. Try again.';
            return;
        }

        $rows = [];
        if (($handle = fopen($path, 'r')) !== false) {
            $header = null;
            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(fn ($h) => Str::snake(trim($h)), $data);
                    continue;
                }
                if (count(array_filter($data)) === 0) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $key) {
                    $row[$key] = $data[$i] ?? null;
                }
                $rows[] = $row;
            }
            fclose($handle);
        }

        $this->report = $this->importRows($rows);

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Import complete.']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    protected function importRows(array $rows): array
    {
        $createdEvents = 0;
        $updatedEvents = 0;
        $createdLines = 0;
        $createdMarketPlans = 0;
        $updatedMarketPlans = 0;
        $skippedLines = 0;
        $matcher = app(EventMatchingService::class);
        $nullIfBlank = function ($value): ?string {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };
        $parseDateOrNull = function ($value): ?Carbon {
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

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? $row['event'] ?? ''));
            if ($name === '') {
                $skippedLines++;
                continue;
            }

            try {
                $eventPayload = [
                    'name' => $name,
                    'venue' => $nullIfBlank($row['venue'] ?? null),
                    'city' => $nullIfBlank($row['city'] ?? null),
                    'state' => $nullIfBlank($row['state'] ?? null),
                    'starts_at' => $parseDateOrNull($row['starts_at'] ?? null),
                    'ends_at' => $parseDateOrNull($row['ends_at'] ?? null),
                    'due_date' => $parseDateOrNull($row['due_date'] ?? null),
                    'ship_date' => $parseDateOrNull($row['ship_date'] ?? null),
                    'status' => $nullIfBlank($row['status'] ?? 'published') ?? 'published',
                    'notes' => $nullIfBlank($row['notes'] ?? null),
                ];

                $event = Event::query()->firstOrCreate([
                    'name' => $eventPayload['name'],
                    'starts_at' => $eventPayload['starts_at'],
                ], [
                    'venue' => $eventPayload['venue'],
                    'city' => $eventPayload['city'],
                    'state' => $eventPayload['state'],
                    'ends_at' => $eventPayload['ends_at'],
                    'due_date' => $eventPayload['due_date'],
                    'ship_date' => $eventPayload['ship_date'],
                    'status' => $eventPayload['status'],
                    'notes' => $eventPayload['notes'],
                ]);

                if ($event->wasRecentlyCreated) {
                    $createdEvents++;
                } else {
                    $updatedEvents++;
                    $event->fill([
                        'venue' => $eventPayload['venue'],
                        'city' => $eventPayload['city'],
                        'state' => $eventPayload['state'],
                        'ends_at' => $eventPayload['ends_at'],
                        'due_date' => $eventPayload['due_date'],
                        'ship_date' => $eventPayload['ship_date'],
                        'status' => $eventPayload['status'],
                        'notes' => $eventPayload['notes'],
                    ])->save();
                }

                $scentName = trim((string) ($row['scent'] ?? ''));
                $sizeLabel = trim((string) ($row['size'] ?? ''));
                if ($scentName === '' || $sizeLabel === '') {
                    continue;
                }

                $scent = Scent::query()->where('name', $scentName)->first();
                if (! $scent) {
                    $this->warnings[] = "Unknown scent: {$scentName}";
                    $skippedLines++;
                    continue;
                }

                $size = Size::query()
                    ->where('label', $sizeLabel)
                    ->orWhere('code', $sizeLabel)
                    ->first();
                if (! $size) {
                    $this->warnings[] = "Unknown size: {$sizeLabel}";
                    $skippedLines++;
                    continue;
                }

                EventShipment::query()->create([
                    'event_id' => $event->id,
                    'scent_id' => $scent->id,
                    'size_id' => $size->id,
                    'planned_qty' => (int) ($row['planned_qty'] ?? $row['planned'] ?? 0),
                    'sent_qty' => $row['sent_qty'] ?? $row['sent'] ?? null,
                    'returned_qty' => $row['returned_qty'] ?? $row['returned'] ?? null,
                    'sold_qty' => $row['sold_qty'] ?? null,
                ]);
                $createdLines++;

                $eventTitle = $name;
                $scentLabel = $scentName;
                $rawBoxCount = $row['sent_qty'] ?? $row['sent'] ?? $row['planned_qty'] ?? $row['planned'] ?? 0;
                $boxCount = max(0, (int) round((float) $rawBoxCount));
                $eventDate = $eventPayload['starts_at']?->toDateString();

                if ($eventTitle === '' || $scentLabel === '' || ! $eventDate || $boxCount <= 0) {
                    continue;
                }

                $marketPlan = MarketPlan::query()
                    ->where('event_title', $eventTitle)
                    ->whereDate('event_date', $eventDate)
                    ->where('scent', $scentLabel)
                    ->first();

                if ($marketPlan) {
                    $marketPlan->fill([
                        'normalized_title' => $matcher->normalizeTitle($eventTitle),
                        'box_type' => 'full',
                        'box_count' => $boxCount,
                        'status' => 'published',
                    ])->save();
                    $updatedMarketPlans++;
                } else {
                    MarketPlan::query()->create([
                        'event_title' => $eventTitle,
                        'event_date' => $eventDate,
                        'scent' => $scentLabel,
                        'normalized_title' => $matcher->normalizeTitle($eventTitle),
                        'box_type' => 'full',
                        'box_count' => $boxCount,
                        'status' => 'published',
                    ]);
                    $createdMarketPlans++;
                }
            } catch (\Throwable $e) {
                Log::warning('events import row failed', [
                    'row' => $row,
                    'error' => $e->getMessage(),
                ]);
                $skippedLines++;
                continue;
            }
        }

        return [
            'events_created' => $createdEvents,
            'events_updated' => $updatedEvents,
            'shipments_created' => $createdLines,
            'market_plans_created' => $createdMarketPlans,
            'market_plans_updated' => $updatedMarketPlans,
            'skipped' => $skippedLines,
        ];
    }

    public function render()
    {
        return view('livewire.events.import')->layout('layouts.app');
    }
}
