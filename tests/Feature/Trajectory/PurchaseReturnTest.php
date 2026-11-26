<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\AllocationDetail;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2027-02-15 12:00:00', 'UTC'));
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->base = '/trajectory/spaces/'.$this->space->id;
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'returns:cash', 'name' => 'Checking', 'kind' => 'cash', 'currency' => 'USD']);
    $this->add = function ($key, $cents, $date, $merchant = 'Target', ?string $flow = null, ?Account $account = null) {
        $account ??= $this->account;
        app(LedgerService::class)->ingest($account, [['id' => $key, 'merchant' => $merchant, 'amount_cents' => $cents, 'date' => $date]]);
        $tx = Transaction::where('source_key', $account->source_key.':'.$key)->firstOrFail();
        if ($flow !== null) {
            $tx->update(['flow' => $flow, 'category' => 'shopping', 'reviewed' => true]);
        }

        return $tx;
    };
    $this->rows = fn () => collect(app(LedgerService::class)->entries($this->space))->keyBy('id');
});

test('a new retail credit offsets the exact reviewed purchase across periods in analysis and budget without changing either bank row', function () {
    $purchase = ($this->add)('purchase', -1743, '2027-01-30', flow: 'expense');
    $return = ($this->add)('return', 1743, '2027-02-02');
    ($this->add)('return', 1743, '2027-02-02'); // replay
    $this->actingAs($this->owner);
    $feb = $this->getJson($this->base.'/analysis?from=2027-02-01&through=2027-02-15')->assertOk()->json();
    $credit = collect($feb['entries'])->firstWhere('id', $return->id);
    expect($credit['refund_match'])->toMatchArray(['status' => 'matched', 'role' => 'refund', 'amount_cents' => 1743,
        'counterpart_transaction_id' => $purchase->id, 'counterpart_date' => '2027-01-30', 'inferred' => true, 'original_flow' => 'unclassified_deposit'])
        ->and($credit['category'])->toBe('shopping')->and($credit['reviewed'])->toBeFalse()
        ->and($feb['summary']['spending_cents'])->toBe(-1743)
        ->and(collect($feb['inflows'])->firstWhere('kind', 'refund')['amount_cents'])->toBe(1743);
    $both = $this->getJson($this->base.'/analysis?from=2027-01-30&through=2027-02-15')->assertOk()->json();
    expect($both['summary']['spending_cents'])->toBe(0)
        ->and(collect($both['entries'])->firstWhere('id', $purchase->id)['refund_match']['counterpart_transaction_id'])->toBe($return->id);
    Record::create(['space_id' => $this->space->id, 'kind' => 'budget', 'name' => 'Shopping', 'active' => true,
        'data' => ['purpose' => 'spending', 'category' => 'shopping', 'monthly_cents' => 10000, 'effective_on' => '2027-02-01', 'reviewed' => true]]);
    $budget = $this->getJson($this->base.'/budget-envelopes?month=2027-02')->assertOk()->json();
    expect(collect($budget['rows'])->firstWhere('category', 'shopping')['spent_cents'])->toBe(-1743)
        ->and($return->fresh()->flow)->toBe('unclassified_deposit')->and($return->fresh()->reviewed)->toBeFalse();
    $return->update(['flow' => 'refund', 'category' => 'shopping', 'reviewed' => true]);
    expect(($this->rows)()[$return->id]['refund_match']['inferred'])->toBeFalse();
});

test('ambiguity in either direction withdraws a provisional match regardless of import order and range', function () {
    $return = ($this->add)('r', 1743, '2027-02-04');
    $p1 = ($this->add)('p1', -1743, '2027-02-01', flow: 'expense');
    expect(($this->rows)()[$return->id])->toHaveKey('refund_match');
    $p2 = ($this->add)('p2', -1743, '2027-01-30', flow: 'expense');
    $narrow = fn () => app(LedgerService::class)->entries($this->space, ids: [$return->id])[0];
    expect($narrow())->not->toHaveKey('refund_match')->and($narrow()['flow'])->toBe('unclassified_deposit');
    $p2->update(['removed' => true]);
    expect($narrow()['refund_match']['counterpart_transaction_id'])->toBe($p1->id);
    $r2 = ($this->add)('r2', 1743, '2027-02-05', flow: 'refund');
    expect($narrow())->not->toHaveKey('refund_match');
    $r2->update(['pending' => true]);
    expect($narrow())->toHaveKey('refund_match');
});

test('a pair requires identifiable merchants and full opposite bank amounts on one account within thirty days', function () {
    $other = Account::create(['space_id' => $this->space->id, 'source_key' => 'returns:card', 'name' => 'Card', 'kind' => 'credit', 'currency' => 'USD']);
    $purchase = ($this->add)('p', -2399, '2027-01-04', flow: 'expense');
    ($this->add)('partial', 1200, '2027-01-05', flow: 'refund');
    ($this->add)('early', 2399, '2027-01-03', flow: 'refund');
    ($this->add)('late', 2399, '2027-02-04', flow: 'refund');
    ($this->add)('different', 2399, '2027-01-06', 'Other Merchant', 'refund');
    ($this->add)('card', 2399, '2027-01-07', flow: 'refund', account: $other);
    ($this->add)('x1', -500, '2027-01-06', 'Zelle', 'expense');
    ($this->add)('x2', 500, '2027-01-07', 'Zelle', 'refund');
    ($this->add)('wire', 2399, '2027-01-07', flow: 'transfer');
    expect(($this->rows)()->filter(fn ($r) => isset($r['refund_match'])))->toBeEmpty();
    $return = ($this->add)('ret', 2399, '2027-02-03', flow: 'refund');
    expect(($this->rows)()[$return->id]['refund_match']['counterpart_transaction_id'])->toBe($purchase->id);
    // A partial cross-space allocation cannot pair a split or disclose the bank total to its recipient.
    $business = Space::create(['kind' => 'business', 'tenant_id' => $this->space->tenant_id, 'owner_user_id' => $this->owner->id, 'name' => 'Other business', 'enabled' => true, 'currency' => 'USD', 'timezone' => 'UTC']);
    Allocation::where('transaction_id', $purchase->id)->update(['amount_cents' => -2000]);
    Allocation::create(['transaction_id' => $purchase->id, 'space_id' => $business->id, 'amount_cents' => -399]);
    expect(($this->rows)()[$return->id])->not->toHaveKey('refund_match')
        ->and(app(LedgerService::class)->entries($business)[0])->not->toHaveKey('refund_match');
});

test('cross-account reconciliation preserves amount, date, type and reviewed exclusions', function () {
    $other = Account::create(['space_id' => $this->space->id, 'source_key' => 'returns:second', 'name' => 'Savings', 'kind' => 'cash', 'currency' => 'USD']);
    $a = ($this->add)('debit', -5000, '2027-02-03', 'BANK TRANSFER');
    $b = ($this->add)('credit', 5000, '2027-02-08', 'OTHER BANK', account: $other);
    ($this->add)('late', 5000, '2027-02-09', 'OTHER BANK', account: $other);
    ($this->add)('same-account', 5000, '2027-02-06', 'OTHER BANK');
    ($this->add)('wrong-amount', 4900, '2027-02-06', 'OTHER BANK', account: $other);
    $this->actingAs($this->owner);
    $get = fn () => collect($this->getJson($this->base.'/reconciliation')->assertOk()->json())->where('left.id', $a->id)->values();
    expect($get()->pluck('right.id')->all())->toBe([$b->id])->and($get()->first()['kind'])->toBe('transfer');
    $a->update(['flow' => 'transfer', 'reviewed' => true]);
    expect($get())->toBeEmpty();
});

test('a full return links an owned split purchase without assigning its refund to a budget account or detail line', function () {
    $purchase = ($this->add)('p', -1299, '2027-01-16', 'Fictional Books', 'expense');
    $credit = ($this->add)('r', 1299, '2027-02-15', 'Fictional Books - Refund');
    $one = fn () => app(LedgerService::class)->entries($this->space, ids: [$credit->id])[0];
    expect($one()['refund_match']['counterpart_transaction_id'])->toBe($purchase->id);
    $school = Account::create(['space_id' => $this->space->id, 'source_key' => 'returns:school', 'name' => 'School budget', 'kind' => 'cash', 'currency' => 'USD']);
    $line = function ($tx, int $index, int $cents, string $category, $account) {
        return AllocationDetail::create(['transaction_id' => $tx->id, 'allocation_id' => Allocation::where('transaction_id', $tx->id)->value('id'),
            'space_id' => $this->space->id, 'budget_account_id' => $account->id, 'revision' => 1, 'line_index' => $index, 'amount_cents' => $cents,
            'category' => $category, 'flow' => $cents > 0 ? 'refund' : 'expense', 'source_fingerprint' => app(\App\Services\Trajectory\AttributionService::class)->fingerprint($tx)]);
    };
    $line($purchase, 0, -600, 'education', $school);
    $line($purchase, 1, -699, 'shopping', $this->account);
    expect($one()['refund_match'])->toMatchArray(['counterpart_transaction_id' => $purchase->id, 'amount_cents' => 1299, 'bank_level' => true, 'allocation_review_required' => true])
        ->and($one()['flow'])->toBe('refund')->and($one()['category'])->toBe('uncategorized')->and($one()['allocation_details'])->toBeEmpty();
    $this->actingAs($this->owner);
    $report = $this->getJson($this->base.'/analysis?from=2027-01-16&through=2027-02-15')->assertOk()->json();
    $rows = collect($report['entries'])->where('direction', 'out');
    $purchaseRows = $rows->where('id', $purchase->id);
    expect($report['summary']['spending_cents'])->toBe(0)->and($purchaseRows)->toHaveCount(2)
        ->and($purchaseRows->pluck('amount_cents')->sort()->values()->all())->toBe([-699, -600])
        ->and($purchaseRows->every(fn ($row) => $row['refund_match']['amount_cents'] === 1299 && $row['refund_match']['counterpart_transaction_id'] === $credit->id))->toBeTrue()
        ->and($rows->firstWhere('id', $credit->id)['attribution_detail_id'])->toBeNull()
        ->and($credit->fresh()->flow)->toBe('unclassified_deposit');
    $credit->update(['category' => 'uncategorized', 'flow' => 'refund', 'reviewed' => true]);
    $line($credit, 0, 600, 'education', $school);
    $line($credit, 1, 699, 'shopping', $this->account);
    expect($one()['refund_match']['allocation_review_required'])->toBeFalse()->and($one()['allocation_details'])->toHaveCount(2);
});

test('reconciliation and one-row refund lookups handle a full-size recent ledger without loading unrelated candidate amounts', function () {
    $other = Account::create(['space_id' => $this->space->id, 'source_key' => 'returns:other', 'name' => 'Reserve', 'kind' => 'cash', 'currency' => 'USD']);
    $merchant = encrypt('Unrelated store', false);
    $batch = [];
    for ($i = 0; $i < 1495; $i++) {
        $batch[] = ['space_id' => $this->space->id, 'source_key' => 'size:'.$i, 'account_id' => $i % 2 === 0 ? $this->account->id : $other->id,
            'amount_cents' => 100000 + $i, 'posted_on' => '2027-02-01', 'flow' => 'refund', 'merchant' => $merchant];
    }
    foreach (array_chunk($batch, 100) as $part) {
        DB::table('trajectory_transactions')->insert($part);
    }
    $purchase = ($this->add)('p', -1743, '2027-02-01', flow: 'expense');
    $credit = ($this->add)('r', 1743, '2027-02-02');
    $bankIn = ($this->add)('bankcredit', 38990, '2027-02-03', 'Bank', account: $other);
    $bankOut = ($this->add)('bankdebit', -38990, '2027-02-03', 'Bank');
    $this->actingAs($this->owner);
    $candidates = $this->getJson($this->base.'/reconciliation')->assertOk()->json();
    expect($candidates)->toHaveCount(1)->and($candidates[0]['left']['id'])->toBe($bankIn->id)->and($candidates[0]['right']['id'])->toBe($bankOut->id);
    DB::enableQueryLog();
    try {
        $rows = app(LedgerService::class)->entries($this->space, ids: [$credit->id]);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => str_replace(['"', '`'], '', $sql))
            ->filter(fn ($sql) => str_contains($sql, 'amount_cents in'));
        expect($rows[0]['refund_match']['counterpart_transaction_id'])->toBe($purchase->id)
            ->and($queries->contains(fn ($sql) => str_contains($sql, '(1743)')))->toBeTrue()
            ->and($queries->contains(fn ($sql) => str_contains($sql, '(-1743)')))->toBeTrue();
    } finally {
        DB::disableQueryLog();
    }
});
