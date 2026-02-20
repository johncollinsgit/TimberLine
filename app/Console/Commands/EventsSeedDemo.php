<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventShipment;
use Illuminate\Console\Command;

class EventsSeedDemo extends Command
{
    protected $signature = 'events:seed-demo {--force}';
    protected $description = 'Seed demo events and shipments for development.';

    public function handle(): int
    {
        if (!app()->environment('local') && !$this->option('force')) {
            $this->error('Refusing to seed outside local. Use --force to override.');
            return self::FAILURE;
        }

        $event = Event::query()->firstOrCreate([
            'name' => 'Spring Market Demo',
        ], [
            'starts_at' => now()->addWeeks(2)->toDateString(),
            'ends_at' => now()->addWeeks(2)->addDay()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'ship_date' => now()->addWeek()->toDateString(),
            'status' => 'planned',
        ]);

        EventShipment::query()->firstOrCreate([
            'event_id' => $event->id,
            'scent_id' => 1,
            'size_id' => 1,
        ], [
            'planned_qty' => 12,
            'sent_qty' => 10,
            'returned_qty' => 2,
        ]);

        $this->info('Demo event seeded.');
        return self::SUCCESS;
    }
}
