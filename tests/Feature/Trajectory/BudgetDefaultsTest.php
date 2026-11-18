<?php

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\AnalysisCategoryService;
use App\Services\Trajectory\BudgetDefaultsService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->space = (new Space)->forceFill(['id' => 41, 'kind' => 'business', 'timezone' => 'UTC']);
    $this->sequence = 0;
    $this->target = fn (array $data = [], array $attributes = []) => (new Record)->forceFill([
        'id' => ++$this->sequence, 'space_id' => 41, 'kind' => 'budget', 'active' => true, 'version' => 1,
        'data' => [...['purpose' => 'spending', 'category' => 'materials', 'monthly_cents' => 10000, 'effective_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reviewed' => true], ...$data],
        ...$attributes,
    ]);
    $catalog = Mockery::mock(AnalysisCategoryService::class);
    $catalog->shouldReceive('definitions')->andReturn([
        ['key' => 'materials', 'parent_key' => null], ['key' => 'transport', 'parent_key' => null],
        ['key' => 'fuel', 'parent_key' => 'transport'], ['key' => 'shopping', 'parent_key' => null],
        ['key' => 'clothing', 'parent_key' => 'shopping'],
    ]);
    app()->instance(AnalysisCategoryService::class, $catalog);
    $this->project = fn (array $rows, string $month = '2026-11-01') => app(BudgetDefaultsService::class)->project($this->space, collect($rows), CarbonImmutable::parse($month));
});

test('a monthly default clones its owned source without replaying last months cash spending', function (): void {
    $source = ($this->target)(['reported_cash_spent_cents' => 5000, 'reported_cash_as_of' => '2026-10-20']);
    $before = $source->getAttributes();
    $result = ($this->project)([$source]);
    $projected = $result['records']->first();
    expect($projected)->not->toBe($source)
        ->and($projected->data['effective_on'])->toBe('2026-11-01')->and($projected->data['ends_on'])->toBe('2026-11-30')
        ->and($projected->data['monthly_cents'])->toBe(10000)->and($projected->data['cash_spending_pending'])->toBeTrue()
        ->and($projected->data)->not->toHaveKeys(['reported_cash_spent_cents', 'reported_cash_as_of'])
        ->and($source->getAttributes())->toBe($before)->and($result['defaults']['0|materials']['record_ids'])->toBe([$source->id]);
});

test('foreign inactive and other-kind records cannot suppress an owned default or affect its context hash', function (): void {
    $owned = ($this->target)();
    $foreign = ($this->target)(['effective_on' => '2026-11-01', 'ends_on' => null], ['space_id' => 42]);
    $inactive = ($this->target)(['effective_on' => '2026-11-01', 'ends_on' => null], ['active' => false]);
    $goal = ($this->target)(['effective_on' => '2026-11-01', 'ends_on' => null], ['kind' => 'goal']);
    $misc = (object) ['purpose' => 'spending'];
    $rows = [$owned, $foreign, $inactive, $goal, $misc, ['category' => 'materials']];
    $before = array_map(fn ($row) => $row instanceof Record ? $row->getAttributes() : $row, $rows);
    $result = ($this->project)($rows);
    expect($result['defaults']['0|materials']['record_ids'])->toBe([$owned->id])
        ->and($result['records'][1]->getAttributes())->toBe($before[1])->and($result['records'][2]->getAttributes())->toBe($before[2])
        ->and($result['records'][3]->getAttributes())->toBe($before[3])->and($result['records'][5])->toBe($before[5]);
    $service = app(BudgetDefaultsService::class);
    expect($service->contextHash($this->space, collect($rows), 'materials', null))
        ->toBe($service->contextHash($this->space, collect([$owned]), 'materials', null));
});

test('malformed legacy dates stay untouched instead of crashing or becoming a default', function (): void {
    $old = ($this->target)(['effective_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    $badEnd = ($this->target)(['ends_on' => '2026-10-99']);
    $badFrom = ($this->target)(['category' => 'fuel', 'effective_on' => '2026-10-32']);
    $reversed = ($this->target)(['category' => 'clothing', 'ends_on' => '2026-09-30']);
    $ancient = ($this->target)(['category' => 'utilities', 'effective_on' => '1800-01-01', 'ends_on' => '1800-01-31']);
    $rows = [$old, $badEnd, $badFrom, $reversed, $ancient];
    $before = array_map(fn ($row) => $row->getAttributes(), $rows);
    $result = ($this->project)($rows);
    expect($result['defaults'])->toBe([])
        ->and($result['records']->map->getAttributes()->all())->toBe($before);
});

test('an explicit stop partial period rollover or month-only override never becomes a repeating default', function (): void {
    $old = ($this->target)(['effective_on' => '2026-09-01', 'ends_on' => null]);
    $stop = ($this->target)(['carry_forward' => false]);
    $partial = ($this->target)(['category' => 'fuel', 'effective_on' => '2026-10-17']);
    $rolling = ($this->target)(['category' => 'clothing', 'rollover' => true]);
    $once = ($this->target)(['category' => 'shopping', 'month_only' => true]);
    $result = ($this->project)([$old, $stop, $partial, $rolling, $once]);
    expect($result['defaults'])->toBe([]);
});

test('later child cohorts suppress expired generic parents even when both would otherwise inherit', function (): void {
    $parent = ($this->target)(['category' => 'transport', 'monthly_cents' => 50000, 'effective_on' => '2026-01-01']);
    $child = ($this->target)(['category' => 'fuel', 'monthly_cents' => 35000, 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $result = ($this->project)([$parent, $child], '2026-12-01');
    expect($result['defaults'])->not->toHaveKey('0|transport')->and($result['defaults']['0|fuel']['record_ids'])->toBe([$child->id]);
    $newParent = ($this->target)(['category' => 'shopping', 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $oldChild = ($this->target)(['category' => 'clothing', 'effective_on' => '2026-01-01']);
    $reverse = ($this->project)([$oldChild, $newParent], '2026-12-01');
    expect($reverse['defaults'])->not->toHaveKey('0|clothing')->and($reverse['defaults']['0|shopping']['record_ids'])->toBe([$newParent->id]);
});

test('a later parent or child in a different program cannot suppress the owned program default', function (): void {
    $parent = ($this->target)(['category' => 'transport', 'program_record_id' => 7, 'effective_on' => '2026-01-01']);
    $child = ($this->target)(['category' => 'fuel', 'effective_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $result = ($this->project)([$parent, $child], '2026-12-01');
    expect($result['defaults']['7|transport']['record_ids'])->toBe([$parent->id])
        ->and($result['defaults']['0|fuel']['record_ids'])->toBe([$child->id]);
});
