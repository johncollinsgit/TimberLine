<?php

namespace App\Livewire\Events;

use App\Models\MarketPlan;
use App\Models\Event;
use App\Models\EventShipment;
use App\Models\Scent;
use App\Models\Size;
use App\Services\EventMatchingService;
use Carbon\Carbon;
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

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? $row['event'] ?? ''));
            if ($name === '') {
                $skippedLines++;
                continue;
            }

            $event = Event::query()->firstOrCreate([
                'name' => $name,
                'starts_at' => $row['starts_at'] ?? null,
            ], [
                'venue' => $row['venue'] ?? null,
                'city' => $row['city'] ?? null,
                'state' => $row['state'] ?? null,
                'ends_at' => $row['ends_at'] ?? null,
                'due_date' => $row['due_date'] ?? null,
                'ship_date' => $row['ship_date'] ?? null,
                'status' => $row['status'] ?? 'planned',
                'notes' => $row['notes'] ?? null,
            ]);

            if ($event->wasRecentlyCreated) {
                $createdEvents++;
            } else {
                $updatedEvents++;
                $event->fill([
                    'venue' => $row['venue'] ?? $event->venue,
                    'city' => $row['city'] ?? $event->city,
                    'state' => $row['state'] ?? $event->state,
                    'ends_at' => $row['ends_at'] ?? $event->ends_at,
                    'due_date' => $row['due_date'] ?? $event->due_date,
                    'ship_date' => $row['ship_date'] ?? $event->ship_date,
                    'status' => $row['status'] ?? $event->status,
                ])->save();
            }

            $scentName = trim((string) ($row['scent'] ?? ''));
            $sizeLabel = trim((string) ($row['size'] ?? ''));
            if ($scentName === '' || $sizeLabel === '') {
                continue;
            }

            $scent = Scent::query()->where('name', $scentName)->first();
            if (!$scent) {
                $this->warnings[] = "Unknown scent: {$scentName}";
                $skippedLines++;
                continue;
            }

            $size = Size::query()
                ->where('label', $sizeLabel)
                ->orWhere('code', $sizeLabel)
                ->first();
            if (!$size) {
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

            $eventDate = null;
            try {
                $startsAt = trim((string) ($row['starts_at'] ?? ''));
                if ($startsAt !== '') {
                    $eventDate = Carbon::parse($startsAt)->toDateString();
                }
            } catch (\Throwable $e) {
                $eventDate = null;
            }

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
