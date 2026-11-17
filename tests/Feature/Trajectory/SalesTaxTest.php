<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\RecordService;
use App\Services\Trajectory\SalesTaxService;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25', 'UTC'));
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(\App\Services\Trajectory\PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'name' => 'Example bank', 'kind' => 'cash', 'source_key' => 'test:tax-bank', 'balance_cents' => 90000]);
    app(LedgerService::class)->ingest($this->account, [['id' => 'tax-payment', 'date' => '2028-07-20', 'merchant' => 'State Revenue', 'amount_cents' => -12500]]);
    $this->tx = Transaction::where('account_id', $this->account->id)->firstOrFail();
    $this->input = ['transaction_id' => $this->tx->id, 'paid_on' => '2028-07-20', 'amount_cents' => -12500, 'agency' => 'Example revenue agency', 'jurisdiction' => 'Example state', 'tax_type' => 'sales_tax', 'movement' => 'payment', 'source' => 'Example filing receipt', 'reviewed' => true];
    $this->input['expected_bank_source_fingerprint'] = app(SalesTaxService::class)->bankFingerprint($this->space->id, $this->account->id, $this->tx->id, '2028-07-20', -12500, 'State Revenue');
    $this->saveTax = fn (array $change = [], ?Record $record = null) => app(RecordService::class)->save($this->owner, $this->space, 'tax_remittance', 'Example filing', [...$this->input, ...$change], $record, $record?->version);
    $this->row = fn (int $id, array $change = []) => ['id' => $id, 'date' => '2028-07-20', 'account_id' => $this->account->id, 'merchant' => 'State Revenue', 'amount_cents' => -12500, 'category' => 'taxes', 'editable' => true, 'reviewed' => true, ...$change];
    $this->metadata = function (int $id, array $change = []): Record {
        $data = [...$this->input, 'transaction_id' => $id, ...$change];
        $data['bank_source_fingerprint'] = app(SalesTaxService::class)->bankFingerprint($this->space->id, $this->account->id, $id, $data['paid_on'], $data['amount_cents'], 'State Revenue');

        return (new Record)->forceFill(['id' => 100 + $id, 'space_id' => $this->space->id, 'kind' => 'tax_remittance', 'active' => true, 'data' => $data]);
    };
    $this->taxReport = fn (array $rows, array $records = [], array $accounts = []) => app(SalesTaxService::class)->build($this->space, collect($rows), collect($records), collect($accounts ?: [$this->account]));
});

test('bank matched tax records are audited and duplicate creation is rejected while edits remain versioned', function (): void {
    $record = ($this->saveTax)();
    expect($record->data['amount_cents'])->toBe(-12500)->and(Event::where('action', 'save_tax_remittance')->where('record_id', $record->id)->exists())->toBeTrue();
    try {
        ($this->saveTax)();
        test()->fail('Duplicate tax payment accepted.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    $updated = ($this->saveTax)(['tax_type' => 'income_tax'], $record);
    expect($updated->version)->toBe(2)->and(Record::where('kind', 'tax_remittance')->count())->toBe(1);
});

test('tax evidence rejects changed amount date sign incomplete filing periods and foreign transactions', function (): void {
    foreach ([['amount_cents' => -12499], ['paid_on' => '2028-07-21'], ['movement' => 'refund'], ['filing_start' => '2028-06-01'], ['filing_start' => '2028-07-01', 'filing_end' => '2028-06-01']] as $change) {
        try {
            ($this->saveTax)($change);
            test()->fail('Invalid evidence accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            expect($e->getStatusCode())->toBe(422);
        }
    }
    $other = User::factory()->create();
    [$foreign] = app(\App\Services\Trajectory\PilotService::class)->prepare($other, null, true);
    $this->tx->update(['space_id' => $foreign->id]);
    expect(fn () => ($this->saveTax)())->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('agency-only payments are unknown and source zero does not imply complete sales tax coverage', function (): void {
    $report = ($this->taxReport)([($this->row)(1)]);
    expect($report['confirmed_payment_cents'])->toBeNull()->and($report['rows'][0]['tax_type'])->toBe('unknown')->and($report['rows'][0]['filing_start'])->toBeNull();
    expect(($this->taxReport)([])['net_remittance_cents'])->toBeNull();
});

test('sales tax payments returns and refunds net once while income tax and draft evidence stay separate', function (): void {
    $rows = [($this->row)(1), ($this->row)(2, ['amount_cents' => 12500]), ($this->row)(3, ['amount_cents' => -88000]), ($this->row)(4, ['amount_cents' => -10000])];
    $records = [($this->metadata)(1), ($this->metadata)(2, ['amount_cents' => 12500, 'movement' => 'returned_payment']), ($this->metadata)(3, ['amount_cents' => -88000, 'tax_type' => 'income_tax']), ($this->metadata)(4, ['amount_cents' => -10000, 'reviewed' => false])];
    $report = ($this->taxReport)($rows, $records);
    expect($report['confirmed_payment_cents'])->toBe(12500)->and($report['returned_payment_cents'])->toBe(12500)->and($report['net_remittance_cents'])->toBe(0)->and($report['refund_cents'])->toBe(0)->and($report['rows'][2]['tax_type'])->toBe('income_tax');
    $records[1] = ($this->metadata)(2, ['amount_cents' => 12500, 'movement' => 'refund']);
    expect(($this->taxReport)($rows, $records)['refund_cents'])->toBe(12500);
});

test('account scope foreign shared pending removed and stale bank matches cannot disclose or count remittances', function (): void {
    $records = [($this->metadata)(1), ($this->metadata)(2), ($this->metadata)(3), ($this->metadata)(4), ($this->metadata)(5)];
    $rows = [($this->row)(1, ['account_id' => 999]), ($this->row)(2, ['space_id' => 999]), ($this->row)(3, ['editable' => false]), ($this->row)(4, ['pending' => true]), ($this->row)(5, ['removed' => true])];
    expect(($this->taxReport)($rows, $records)['rows'])->toBe([]);
    $stale = ($this->taxReport)([($this->row)(1, ['amount_cents' => -12600])], [($this->metadata)(1)]);
    expect($stale['confirmed_payment_cents'])->toBeNull()->and($stale['rows'][0]['reviewed'])->toBeFalse()->and($stale['rows'][0]['issue'])->toContain('changed');
});

test('conflicting duplicated tax records cannot double count a payment', function (): void {
    $a = ($this->metadata)(1);
    $b = ($this->metadata)(1);
    $b->id = 222;
    $report = ($this->taxReport)([($this->row)(1), ($this->row)(1)], [$a, $b]);
    expect($report['rows'])->toHaveCount(1)->and($report['confirmed_payment_cents'])->toBeNull()->and($report['rows'][0]['issue'])->toContain('Conflicting');
});

test('tax components must be complete and sum to cash while unknown components remain unavailable', function (): void {
    foreach ([['tax_principal_cents' => 12500], ['tax_principal_cents' => 12000, 'interest_cents' => 400, 'penalty_cents' => 0, 'other_fees_cents' => 0]] as $change) {
        try {
            ($this->saveTax)($change);
            test()->fail('Partial or mismatched split accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            expect($e->getStatusCode())->toBe(422);
        }
    }
    $split = ['tax_principal_cents' => 12000, 'interest_cents' => 500, 'penalty_cents' => 0, 'other_fees_cents' => 0];
    $record = ($this->saveTax)($split)->fresh();
    $report = ($this->taxReport)([($this->row)($this->tx->id)], [$record]);
    expect($report['rows'][0]['components'])->toBe($split)->and($report['confirmed_payment_cents'])->toBe(12500);
    expect(($this->taxReport)([($this->row)(99)], [($this->metadata)(99)])['rows'][0]['components'])->toBeNull();
});

test('another owner cannot save financial tax evidence in this space', function (): void {
    $outsider = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    try {
        app(RecordService::class)->save($outsider, $this->space, 'tax_remittance', 'Foreign attempt', $this->input);
        test()->fail('Foreign writer accepted.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
    expect(Record::where('kind', 'tax_remittance')->count())->toBe(0);
});

test('dashboard account filter retains only tax evidence linked to transactions from that account', function (): void {
    ($this->saveTax)();
    $dashboard = app(\App\Services\Trajectory\DashboardService::class)->build($this->space, 'custom', from: '2028-07-01', through: '2028-07-25', viewer: $this->owner, accountId: $this->account->id);
    expect($dashboard['sales_tax']['confirmed_payment_cents'])->toBe(12500)->and($dashboard['sales_tax']['rows'][0]['reviewed'])->toBeTrue();
    $other = Account::create(['space_id' => $this->space->id, 'name' => 'Other cash', 'kind' => 'cash', 'source_key' => 'test:other-tax-bank', 'balance_cents' => 0]);
    $otherDashboard = app(\App\Services\Trajectory\DashboardService::class)->build($this->space, 'custom', from: '2028-07-01', through: '2028-07-25', viewer: $this->owner, accountId: $other->id);
    expect($otherDashboard['sales_tax']['rows'])->toBe([])->and($otherDashboard['sales_tax']['confirmed_payment_cents'])->toBeNull();
});

test('merchant or account amendments invalidate confirmation while ordinary category review does not', function (): void {
    $record = ($this->saveTax)()->fresh();
    expect($record->data['bank_source_fingerprint'])->toBeString();
    $changedMerchant = ($this->taxReport)([($this->row)($this->tx->id, ['merchant' => 'Different agency'])], [$record]);
    expect($changedMerchant['confirmed_payment_cents'])->toBeNull();
    $other = Account::create(['space_id' => $this->space->id, 'name' => 'Amended bank', 'kind' => 'cash', 'source_key' => 'test:amended-bank', 'balance_cents' => 0]);
    $changedAccount = ($this->taxReport)([($this->row)($this->tx->id, ['account_id' => $other->id])], [$record], [$other]);
    expect($changedAccount['confirmed_payment_cents'])->toBeNull();
    $categoryOnly = ($this->taxReport)([($this->row)($this->tx->id, ['category' => 'uncategorized', 'version' => 77])], [$record]);
    expect($categoryOnly['confirmed_payment_cents'])->toBe(12500);
});

test('tax saves require the reviewed bank fingerprint and never trust a supplied stored fingerprint', function (): void {
    foreach ([null, 'invalid'] as $fingerprint) {
        expect(fn () => ($this->saveTax)(['expected_bank_source_fingerprint' => $fingerprint]))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    try {
        ($this->saveTax)(['expected_bank_source_fingerprint' => str_repeat('0', 64), 'bank_source_fingerprint' => $this->input['expected_bank_source_fingerprint']]);
        test()->fail('Unreviewed bank evidence accepted.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    $validated = app(RecordService::class)->validate($this->space, 'tax_remittance', $this->input);
    $record = ($this->saveTax)(['bank_source_fingerprint' => str_repeat('0', 64)]);
    expect($validated)->not->toHaveKey('expected_bank_source_fingerprint')
        ->and($record->data)->not->toHaveKey('expected_bank_source_fingerprint')
        ->and($record->data['bank_source_fingerprint'])->toBe($this->input['expected_bank_source_fingerprint']);
});

test('stale tax forms cannot rebind confirmation after a bank merchant amendment', function (): void {
    $record = ($this->saveTax)();
    $this->tx->update(['merchant' => 'Different income tax agency', 'version' => $this->tx->version + 1]);
    try {
        ($this->saveTax)([], $record);
        test()->fail('Stale tax review rebound to changed bank facts.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    expect($record->fresh()->version)->toBe(1)->and(Event::where('action', 'save_tax_remittance')->count())->toBe(1);
    $report = ($this->taxReport)([($this->row)($this->tx->id, ['merchant' => $this->tx->merchant])], [$record->fresh()]);
    expect($report['confirmed_payment_cents'])->toBeNull()->and($report['rows'][0]['reviewed'])->toBeFalse();
    $updated = ($this->saveTax)(['expected_bank_source_fingerprint' => $report['rows'][0]['bank_source_fingerprint'], 'agency' => 'Different income tax agency', 'tax_type' => 'income_tax', 'source' => 'New agency receipt'], $record);
    expect($updated->version)->toBe(2)->and($updated->data['tax_type'])->toBe('income_tax');
});

test('tax admission binds account identity but ignores ordinary category and transaction version edits', function (): void {
    $this->tx->update(['category' => 'taxes', 'reviewed' => true, 'version' => $this->tx->version + 1]);
    $record = ($this->saveTax)();
    $other = Account::create(['space_id' => $this->space->id, 'name' => 'Amended bank', 'kind' => 'cash', 'source_key' => 'test:tax-amendment', 'balance_cents' => 0]);
    $this->tx->update(['account_id' => $other->id]);
    try {
        ($this->saveTax)([], $record);
        test()->fail('Changed bank account accepted with stale admission.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    expect($record->fresh()->version)->toBe(1);
});

test('bank facts are revalidated under the save lock after initial admission', function (): void {
    $service = new class($this->tx) extends SalesTaxService
    {
        private bool $amended = false;

        public function __construct(private Transaction $source) {}

        public function validateRemittance(\App\Models\Trajectory\Space $space, array $data): string
        {
            $fingerprint = parent::validateRemittance($space, $data);
            if (! $this->amended) {
                $this->amended = true;
                $this->source->update(['merchant' => 'Source amended between validations']);
            }

            return $fingerprint;
        }
    };
    app()->instance(SalesTaxService::class, $service);
    try {
        ($this->saveTax)();
        test()->fail('Bank amendment between validations accepted.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    expect(Record::where('kind', 'tax_remittance')->count())->toBe(0)
        ->and(Event::where('action', 'save_tax_remittance')->count())->toBe(0);
});
