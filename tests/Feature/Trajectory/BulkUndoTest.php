<?php

use App\Http\Controllers\Trajectory\TrajectoryController;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MoneyFlowService;
use App\Services\Trajectory\PilotService;
use Illuminate\Http\Request;

beforeEach(function (): void {
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:bulk-undo-card', 'name' => 'Fictional card', 'kind' => 'credit', 'balance_cents' => 300003]);
    $this->ledger = app(LedgerService::class);
    $this->ledger->ingest($this->account, [
        ['id' => 'one', 'date' => now()->toDateString(), 'merchant' => 'Fictional mixed store order one', 'amount_cents' => -10001, 'category' => 'shopping'],
        ['id' => 'two', 'date' => now()->toDateString(), 'merchant' => 'Fictional mixed store order two', 'amount_cents' => -20002, 'category' => 'shopping'],
    ]);
    $rows = Transaction::where('account_id', $this->account->id)->orderBy('id')->get();
    foreach ($rows as $row) {
        $row->update(['category' => 'shopping', 'flow' => 'expense', 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Imported shopping purchase; purpose unreviewed.']);
    }
    $this->selected = $rows[0]->fresh();
    $this->sibling = $rows[1]->fresh();
    $this->original = $this->selected->only(['category', 'flow', 'reviewed', 'explanation', 'face_punched', 'bullshit_spending']);
    $this->ledger->bulkClassify($this->owner, $this->space, $rows->map(fn ($row) => ['id' => $row->id, 'version' => $row->version])->all(), ['category' => 'uncategorized', 'flow' => 'asset_transfer', 'face_punched' => false, 'bullshit_spending' => false]);
    $this->selected->refresh();
    $this->sibling->refresh();
    $this->url = '/trajectory/spaces/'.$this->space->id.'/transactions/'.$this->selected->id.'/undo';
    $this->actingAs($this->owner);
});

test('undo restores one exact bulk review to its unreviewed purchase without changing cash source siblings flags rules or allocations', function (): void {
    $source = $this->selected->only(['space_id', 'account_id', 'source_key', 'source', 'merchant', 'amount_cents', 'posted_on', 'pending', 'removed']);
    $sibling = $this->sibling->getAttributes();
    $allocations = Allocation::orderBy('id')->get()->toArray();
    $account = $this->account->fresh()->getAttributes();
    $this->postJson($this->url, ['version' => 2])->assertOk()->assertJsonPath('ok', true);
    $saved = $this->selected->fresh();
    expect($saved->only(array_keys($this->original)))->toBe($this->original)->and($saved->version)->toBe(3)
        ->and($saved->only(array_keys($source)))->toEqual($source)->and($this->sibling->fresh()->getAttributes())->toBe($sibling)
        ->and(Allocation::orderBy('id')->get()->toArray())->toBe($allocations)->and($this->account->fresh()->getAttributes())->toBe($account)
        ->and(Record::count())->toBe(0)->and(Transaction::count())->toBe(2);
    $event = Event::where('action', 'undo_classify')->sole();
    expect($event->record_id)->toBe($saved->id)->and($event->before['reviewed'])->toBeTrue()->and($event->after['reviewed'])->toBeFalse()->and($event->after['version'])->toBe(3);
    $report = app(MoneyFlowService::class)->build($this->space, collect($this->ledger->entries($this->space)), collect([$this->account]));
    expect($report['views']['spending']['review_outflow_cents'])->toBe(10001)->and($report['views']['cash']['outflow_cents'])->toBeNull();
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    $this->postJson($this->url, ['version' => 3])->assertConflict();
    expect(Event::where('action', 'undo_classify')->count())->toBe(1)->and($saved->fresh()->version)->toBe(3);
});

test('a source update cannot undo an older bulk classification using either the old or new version', function (): void {
    $this->ledger->ingest($this->account, [['id' => 'one', 'date' => now()->toDateString(), 'merchant' => $this->selected->merchant, 'amount_cents' => -15000]]);
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    $this->postJson($this->url, ['version' => $this->selected->fresh()->version])->assertConflict();
    expect($this->selected->fresh()->amount_cents)->toBe(-15000)->and(Event::where('action', 'undo_classify')->count())->toBe(0);
});

test('bulk undo rejects changed raw source identity even when a stale version was retained', function (string $change): void {
    $source = $this->selected->source;
    if ($change === 'amount') {
        $source['amount_cents'] = -9999;
    } elseif ($change === 'id') {
        $source['id'] = 'other-source';
    } else {
        $source['id'] = ['malformed'];
    }
    $this->selected->update(['source' => $source]);
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    expect($this->selected->fresh()->flow)->toBe('asset_transfer')->and(Event::where('action', 'undo_classify')->count())->toBe(0);
})->with(['amount', 'id', 'malformed']);

test('bulk undo rejects pending removed and changed classification sources', function (array $change): void {
    $this->selected->update($change);
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    expect(Event::where('action', 'undo_classify')->count())->toBe(0);
})->with(['pending' => [['pending' => true]], 'removed' => [['removed' => true]], 'classification drift' => [['category' => 'food']]]);

test('bulk undo requires the complete single owned allocation without changing a split', function (string $change): void {
    $ownerAllocation = Allocation::where('transaction_id', $this->selected->id)->sole();
    if ($change === 'absent') {
        $ownerAllocation->delete();
    } elseif ($change === 'partial') {
        $ownerAllocation->update(['amount_cents' => -5000]);
    } else {
        [$foreign] = app(PilotService::class)->prepare(User::factory()->create(), null, true);
        $ownerAllocation->update(['amount_cents' => 0]);
        Allocation::create(['transaction_id' => $this->selected->id, 'space_id' => $foreign->id, 'amount_cents' => -10001]);
    }
    $before = Allocation::orderBy('id')->get()->toArray();
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    expect(Allocation::orderBy('id')->get()->toArray())->toBe($before)->and(Event::where('action', 'undo_classify')->count())->toBe(0);
})->with(['absent', 'partial', 'split']);

test('bulk undo cannot restore a prior snapshot that includes extra fields or changed spending flags', function (string $change): void {
    $event = Event::where('record_id', $this->selected->id)->where('action', 'bulk_classify')->sole();
    $before = $event->before;
    if ($change === 'extra source field') {
        $before['amount_cents'] = -1;
    } else {
        $before['bullshit_spending'] = true;
    }
    $event->update(['before' => $before]);
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    expect($this->selected->fresh()->amount_cents)->toBe(-10001)->and($this->selected->fresh()->bullshit_spending)->toBeFalse()->and(Event::where('action', 'undo_classify')->count())->toBe(0);
})->with(['extra source field', 'changed flags']);

test('bulk undo refuses bound tax or medical rows before changing their classification', function (string $kind): void {
    Record::create(['space_id' => $this->space->id, 'kind' => $kind, 'name' => 'Fictional bound financial evidence', 'active' => true, 'data' => ['transaction_id' => $this->selected->id, 'reviewed' => false]]);
    $this->postJson($this->url, ['version' => 2])->assertUnprocessable();
    expect($this->selected->fresh()->reviewed)->toBeTrue()->and(Event::where('action', 'undo_classify')->count())->toBe(0);
})->with(['tax_remittance', 'medical_payment']);

test('foreign users and changed account ownership cannot undo an authorized-space row', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreign] = app(PilotService::class)->prepare($other, null, true);
    $this->actingAs($other)->postJson($this->url, ['version' => 2])->assertForbidden();
    $this->account->update(['space_id' => $foreign->id]);
    $this->actingAs($this->owner)->postJson($this->url, ['version' => 2])->assertConflict();
    expect($this->selected->fresh()->version)->toBe(2)->and(Event::where('action', 'undo_classify')->count())->toBe(0);
});

test('current space authorization replaces stale route-model ownership under the undo lock', function (): void {
    $stale = Space::findOrFail($this->space->id);
    $other = User::factory()->create();
    $this->space->update(['owner_user_id' => $other->id]);
    $request = Request::create($this->url, 'POST', ['version' => 2]);
    $request->setUserResolver(fn () => $this->owner);
    expect(fn () => app(TrajectoryController::class)->undo($request, $stale, $this->selected, app(FinanceAccess::class)))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->selected->fresh()->version)->toBe(2)->and(Event::where('action', 'undo_classify')->count())->toBe(0);
});

test('a newer individual review remains the only undo target after a bulk review', function (): void {
    $this->ledger->classify($this->owner, $this->space, $this->selected, ['version' => 2, 'category' => 'shopping', 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false]);
    $this->postJson($this->url, ['version' => 2])->assertConflict();
    $this->postJson($this->url, ['version' => 3])->assertOk();
    expect($this->selected->fresh()->flow)->toBe('asset_transfer')->and($this->selected->fresh()->reviewed)->toBeTrue()->and($this->selected->fresh()->version)->toBe(4);
});
