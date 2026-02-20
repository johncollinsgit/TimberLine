<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventShipment;
use App\Models\Scent;
use App\Models\Size;
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

        $createdEvents = 0;
        $createdLines = 0;
        $skippedLines = 0;

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
        }

        $this->report = [
            'events' => $createdEvents,
            'shipments' => $createdLines,
            'skipped' => $skippedLines,
        ];

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Import complete.']);
    }

    public function render()
    {
        return view('livewire.events.import')->layout('layouts.app');
    }
}
