<?php

use App\Livewire\Events\Browse;
use App\Livewire\Events\ImportMarketBoxPlans;
use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('market box plans import creates event instances and box lines', function () {
    $component = new ImportMarketBoxPlans();
    $method = new ReflectionMethod($component, 'importRows');
    $method->setAccessible(true);

    $report = $method->invoke($component, [
        [
            'event_title' => 'TR Winter Pop Up 01.10.26',
            'event_state' => 'sc',
            'event_starts_at' => '2026-01-10',
            'event_ends_at' => '2026-01-10',
            'event_status' => 'completed',
            'event_notes_raw' => 'Runner: Jess. Strong rain.',
            'source_file' => '2026.xlsx',
            'sheet_title' => 'TR Winter Pop Up 01.10.26',
            'scent_raw' => 'Blue Ridge',
            'box_count_sent' => 2,
        ],
        [
            'event_title' => 'TR Winter Pop Up 01.10.26',
            'event_state' => 'sc',
            'event_starts_at' => '2026-01-10',
            'event_ends_at' => '2026-01-10',
            'event_status' => 'completed',
            'source_file' => '2026.xlsx',
            'sheet_title' => 'TR Winter Pop Up 01.10.26',
            'scent_raw' => 'Moss Trail / Cedar Smoke',
            'box_count_sent' => 1,
            'is_split_box' => 1,
        ],
    ]);

    expect($report['event_instances_created'])->toBe(1);
    expect($report['event_instances_updated'])->toBe(1);
    expect($report['box_plans_created'])->toBe(2);
    expect($report['skipped'])->toBe(0);
    expect($report['import_batch_id'])->not->toBe('');

    $instance = EventInstance::query()->first();
    expect($instance)->not->toBeNull();
    expect((string) $instance->title)->toBe('TR Winter Pop Up, SC');
    expect((string) $instance->status)->toBe('completed');
    expect((string) $instance->source_file)->toBe('2026.xlsx');
    expect((string) $instance->source_sheet)->toBe('TR Winter Pop Up 01.10.26');

    $lines = EventBoxPlan::query()->where('event_instance_id', $instance->id)->orderBy('id')->get();
    expect($lines)->toHaveCount(2);
    expect((string) $lines[0]->scent_raw)->toBe('Blue Ridge');
    expect((float) $lines[1]->box_count_sent)->toBe(1.0);
    expect((bool) $lines[1]->is_split_box)->toBeTrue();
});

test('market box plans import delete batch removes imported rows', function () {
    $component = new ImportMarketBoxPlans();
    $method = new ReflectionMethod($component, 'importRows');
    $method->setAccessible(true);

    $report = $method->invoke($component, [[
        'event_title' => 'TR Winter Pop Up 01.10.26',
        'event_state' => 'SC',
        'event_starts_at' => '2026-01-10',
        'event_status' => 'completed',
        'sheet_title' => 'TR Winter Pop Up 01.10.26',
        'scent_raw' => 'Blue Ridge',
        'box_count_sent' => 2,
    ]]);

    $batchId = (string) $report['import_batch_id'];
    $livewire = Livewire::test(ImportMarketBoxPlans::class)
        ->set('selectedBatchId', $batchId)
        ->call('deleteSelectedBatch');

    $livewire->assertSet('selectedBatchId', null);
    expect(EventInstance::query()->count())->toBe(0);
    expect(EventBoxPlan::query()->count())->toBe(0);
});

test('browse event instances shows imported box plan history', function () {
    $instance = EventInstance::query()->create([
        'title' => 'TR Winter Pop Up, SC',
        'state' => 'SC',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'status' => 'completed',
        'notes' => 'Runner Jess. Selling hours 6.',
    ]);

    EventBoxPlan::query()->create([
        'event_instance_id' => $instance->id,
        'scent_raw' => 'Blue Ridge',
        'box_count_sent' => 2,
    ]);

    Livewire::test(Browse::class)
        ->assertSeeText('Browse Event Instances')
        ->assertSeeText('TR Winter Pop Up, SC')
        ->assertSeeText('2.00');
});
