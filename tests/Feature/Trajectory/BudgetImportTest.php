<?php

use App\Models\Trajectory\Record;
use App\Models\User;
use App\Services\Trajectory\BudgetImportService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->importer = app(BudgetImportService::class);
});

function withFictionalBudgetCsv(string $csv, Closure $callback): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'fictional-budget-');
    file_put_contents($path, $csv);
    try {
        return $callback($path);
    } finally {
        unlink($path);
    }
}

function fictionalBudgetTemplate(): string
{
    return "name,category,monthly_cents,purpose,effective_on,source,reviewed,source_id\nFictional groceries,groceries,12345,spending,2027-01-01,Fictional plan,0,fictional-budget:groceries:2027-01\n";
}

test('budget template preserves integer cents and source identity without creating records during preview', function () {
    withFictionalBudgetCsv(fictionalBudgetTemplate(), function ($path) {
        $preview = $this->importer->read($path, $this->space);
        expect($preview['mapping_required'])->toBeFalse()
            ->and($preview['rows'][0]['monthly_cents'])->toBe(12345)
            ->and($preview['rows'][0]['source_id'])->toBe('fictional-budget:groceries:2027-01')
            ->and($preview['rows'][0]['reviewed'])->toBeFalse()
            ->and(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(0);
    });
});

test('EveryDollar mode asks for actual column mappings and never substitutes spent amounts', function () {
    $csv = "Item,Group,Planned,Spent\nFictional food,Food group,125.67,999.99\n";
    withFictionalBudgetCsv($csv, function ($path) {
        $preview = $this->importer->read($path, $this->space, 'everydollar');
        expect($preview['mapping_required'])->toBeTrue()->and($preview['rows'])->toBe([])->and($preview['columns'])->toContain('Planned', 'Spent');
        $mapping = ['name' => 'Item', 'category' => 'Group', 'amount' => 'Planned'];
        $defaults = ['amount_units' => 'dollars', 'amount_basis' => 'planned', 'purpose' => 'spending', 'effective_on' => '2027-01-01'];
        $mapped = $this->importer->read($path, $this->space, 'everydollar', $mapping, $defaults);
        expect($mapped['rows'][0]['monthly_cents'])->toBe(12567)->and($mapped['unmapped_categories'])->toBe(['Food group']);
        expect(fn () => $this->importer->read($path, $this->space, 'everydollar', [...$mapping, 'amount' => 'Spent'], $defaults))->toThrow(HttpException::class);
    });
});

test('unrecognized source categories need an explicit allowed category and never silently become uncategorized', function () {
    withFictionalBudgetCsv(str_replace(',groceries,', ',Fictional source group,', fictionalBudgetTemplate()), function ($path) {
        $preview = $this->importer->read($path, $this->space);
        expect($preview['mapping_required'])->toBeTrue();
        expect(fn () => $this->importer->resolve($this->space, $preview))->toThrow(HttpException::class);
        $rows = $this->importer->resolve($this->space, $preview, ['Fictional source group' => 'groceries']);
        expect($rows[0]['category'])->toBe('groceries');
        expect(fn () => $this->importer->resolve($this->space, $preview, ['Fictional source group' => 'invented-category']))->toThrow(HttpException::class);
    });
});

test('replaying budget CSV skips identical targets and preserves user edits with a conflict', function () {
    withFictionalBudgetCsv(fictionalBudgetTemplate(), function ($path) {
        $rows = $this->importer->resolve($this->space, $this->importer->read($path, $this->space));
        expect($this->importer->confirm($this->owner, $this->space, $rows))->toBe(['created' => 1, 'skipped' => 0, 'count' => 1]);
        $second = $this->importer->resolve($this->space, $this->importer->read($path, $this->space));
        expect($this->importer->confirm($this->owner, $this->space, $second)['skipped'])->toBe(1);
        $record = Record::where('space_id', $this->space->id)->where('kind', 'budget')->firstOrFail();
        app(RecordService::class)->save($this->owner, $this->space, 'budget', $record->name, [...$record->data, 'monthly_cents' => 25000], $record, $record->version);
        expect(fn () => $this->importer->confirm($this->owner, $this->space, $second))->toThrow(HttpException::class);
        expect($record->fresh()->data['monthly_cents'])->toBe(25000)->and(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(1);
    });
});

test('generated source IDs replay the same file and warn about corrected file identity', function () {
    $csv = "name,category,monthly_cents,purpose,effective_on\nFictional saving,groceries,10000,savings,2027-01-01\n";
    withFictionalBudgetCsv($csv, function ($path) {
        $first = $this->importer->read($path, $this->space);
        $second = $this->importer->read($path, $this->space);
        expect($first['rows'][0]['source_id'])->toBe($second['rows'][0]['source_id'])
            ->and($first['warnings'])->not->toBeEmpty();
    });
});

test('duplicate identities invalid cents and unconfirmed amount roles fail before writes', function () {
    $cases = [
        fictionalBudgetTemplate()."Another fictional target,groceries,500,spending,2027-01-01,Fictional plan,0,fictional-budget:groceries:2027-01\n",
        str_replace(',12345,', ',123.45,', fictionalBudgetTemplate()),
        str_replace(',12345,', ',-12345,', fictionalBudgetTemplate()),
    ];
    foreach ($cases as $csv) {
        withFictionalBudgetCsv($csv, fn ($path) => expect(fn () => $this->importer->read($path, $this->space))->toThrow(HttpException::class));
    }
    expect(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(0);
});

test('budget confirmation keeps financial spaces isolated', function () {
    $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    withFictionalBudgetCsv(fictionalBudgetTemplate(), function ($path) use ($otherSpace) {
        $rows = $this->importer->resolve($this->space, $this->importer->read($path, $this->space));
        expect(fn () => $this->importer->confirm($this->owner, $otherSpace, $rows))->toThrow(HttpException::class);
        expect(Record::where('space_id', $otherSpace->id)->where('kind', 'budget')->count())->toBe(0);
    });
});

test('web budget preview is encrypted private and inert until confirmed and identical replay creates no duplicate', function () {
    $base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner);
    $upload = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('fictional-budget.csv', fictionalBudgetTemplate());
    $preview = $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'file' => $upload()])->assertOk()->assertJsonPath('count', 1)->assertJsonPath('mapping_required', false);
    expect($preview->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $cached = \Illuminate\Support\Facades\Cache::get('trajectory:import:'.hash('sha256', $preview->json('token')));
    expect($cached)->toBeString()->not->toContain('Fictional groceries')->and(decrypt($cached)['space_id'])->toBe($this->space->id)
        ->and(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(0);
    $this->postJson($base.'/imports/confirm', ['token' => $preview->json('token')])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('skipped', 0);
    $this->postJson($base.'/imports/confirm', ['token' => $preview->json('token')])->assertGone();
    $again = $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'file' => $upload()])->assertOk();
    $this->postJson($base.'/imports/confirm', ['token' => $again->json('token')])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped', 1);
    expect(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(1);
});

test('mapped EveryDollar web flow requires explicit planned columns dates purpose and category selection', function () {
    $base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner);
    $upload = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('fictional-everydollar.csv', "Item,Group,Planned,Spent\nFictional food,Food group,125.67,999.99\n");
    $initial = $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'budget_format' => 'everydollar', 'file' => $upload()])->assertOk()->assertJsonPath('mapping_required', true)->assertJsonPath('count', 0);
    $this->postJson($base.'/imports/confirm', ['token' => $initial->json('token')])->assertUnprocessable();
    $mapped = $this->postJson($base.'/imports/preview', [
        'kind' => 'budget', 'budget_format' => 'everydollar', 'file' => $upload(),
        'column_mapping' => json_encode(['name' => 'Item', 'category' => 'Group', 'amount' => 'Planned']),
        'budget_defaults' => json_encode(['amount_units' => 'dollars', 'amount_basis' => 'planned', 'purpose' => 'spending', 'effective_on' => '2027-01-01']),
    ])->assertOk()->assertJsonPath('preview.0.monthly_cents', 12567)->assertJsonPath('unmapped_categories.0', 'Food group');
    $this->postJson($base.'/imports/confirm', ['token' => $mapped->json('token')])->assertUnprocessable();
    $this->postJson($base.'/imports/confirm', ['token' => $mapped->json('token'), 'category_map' => ['Food group' => 'groceries'], 'rows' => [['monthly_cents' => 99999999]]])->assertOk()->assertJsonPath('created', 1);
    $record = Record::where('space_id', $this->space->id)->where('kind', 'budget')->firstOrFail();
    expect($record->data['monthly_cents'])->toBe(12567)->and($record->data['category'])->toBe('groceries')->and($record->data['effective_on'])->toBe('2027-01-01')->and($record->data['reviewed'])->toBeFalse();
});

test('budget preview tokens cannot be reused by another authorized household member', function () {
    $base = '/trajectory/spaces/'.$this->space->id;
    $member = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    \Illuminate\Support\Facades\DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
    $preview = $this->actingAs($this->owner)->postJson($base.'/imports/preview', ['kind' => 'budget', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('fictional-budget.csv', fictionalBudgetTemplate())])->assertOk();
    $this->actingAs($member)->postJson($base.'/imports/confirm', ['token' => $preview->json('token')])->assertForbidden();
    expect(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(0);
    $this->actingAs($this->owner)->postJson($base.'/imports/confirm', ['token' => $preview->json('token')])->assertOk();
});

test('expired budget previews and account-filtered whole-space imports cannot write targets', function () {
    $base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner);
    $upload = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('fictional-budget.csv', fictionalBudgetTemplate());
    $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'account_id' => 123, 'file' => $upload()])->assertUnprocessable();
    $preview = $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'file' => $upload()])->assertOk();
    $this->travel(21)->minutes();
    $this->postJson($base.'/imports/confirm', ['token' => $preview->json('token')])->assertGone();
    expect(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(0);
});

test('budget source identity remains immutable in the shared record editor and changed duplicates conflict', function () {
    withFictionalBudgetCsv(fictionalBudgetTemplate(), function ($path) {
        $this->importer->confirm($this->owner, $this->space, $this->importer->resolve($this->space, $this->importer->read($path, $this->space)));
        $record = Record::where('space_id', $this->space->id)->where('kind', 'budget')->firstOrFail();
        expect(fn () => app(RecordService::class)->save($this->owner, $this->space, 'budget', $record->name, [...$record->data, 'source_id' => 'replacement-reference'], $record, $record->version))->toThrow(HttpException::class);
        expect(fn () => app(RecordService::class)->save($this->owner, $this->space, 'budget', $record->name, [...$record->data, 'monthly_cents' => 99999]))->toThrow(HttpException::class);
        expect($record->fresh()->data['source_id'])->toBe('fictional-budget:groceries:2027-01')->and($record->fresh()->data['monthly_cents'])->toBe(12345);
    });
});

test('web confirmation rejects a target changed after preview and retains the user correction', function () {
    $base = '/trajectory/spaces/'.$this->space->id;
    $this->actingAs($this->owner);
    $upload = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('fictional-budget.csv', fictionalBudgetTemplate());
    $first = $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'file' => $upload()])->assertOk();
    $this->postJson($base.'/imports/confirm', ['token' => $first->json('token')])->assertOk();
    $stale = $this->postJson($base.'/imports/preview', ['kind' => 'budget', 'file' => $upload()])->assertOk();
    $record = Record::where('space_id', $this->space->id)->where('kind', 'budget')->firstOrFail();
    app(RecordService::class)->save($this->owner, $this->space, 'budget', $record->name, [...$record->data, 'monthly_cents' => 25000], $record, $record->version);
    $this->postJson($base.'/imports/confirm', ['token' => $stale->json('token')])->assertConflict();
    expect($record->fresh()->data['monthly_cents'])->toBe(25000)->and(Record::where('space_id', $this->space->id)->where('kind', 'budget')->count())->toBe(1);
});
