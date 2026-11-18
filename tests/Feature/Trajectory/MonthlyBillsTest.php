<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MonthlyBillsService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class MonthlyBillsTest extends Tests\TestCase
{
    use RefreshDatabase;

    private $space;

    private $cash;

    private $owner;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
        config(['trajectory.enabled' => true]);
        Queue::fake();
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        $this->owner = $owner;
        [$this->space] = app(PilotService::class)->prepare($owner, null, true);
        $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:bills-cash', 'name' => 'Example cash', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 1000000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    }

    private function record(string $kind, string $name, array $data): Record
    {
        return Record::create(['space_id' => $this->space->id, 'kind' => $kind, 'name' => $name, 'data' => $data, 'active' => true, 'version' => 1]);
    }

    private function recurring(string $name, array $data = []): Record
    {
        return $this->record('recurring', $name, [...['account_id' => $this->cash->id, 'merchant' => $name, 'amount_cents' => -10000, 'next_due_on' => '2026-11-24', 'cadence' => 'monthly', 'category' => 'utilities', 'expense_type' => 'fixed', 'confirmed' => true], ...$data]);
    }

    private function transaction(string $merchant, int $amount, string $date, bool $pending = false): Transaction
    {
        $id = 'payment-'.++$this->sequence;
        app(LedgerService::class)->ingest($this->cash, [['id' => $id, 'date' => $date, 'merchant' => $merchant, 'amount_cents' => -$amount, 'pending' => $pending]]);

        return Transaction::where('space_id', $this->space->id)->where('source_key', $this->cash->source_key.':'.$id)->firstOrFail();
    }

    private function report(): array
    {
        return app(MonthlyBillsService::class)->build($this->space, Record::where('space_id', $this->space->id)->get(), collect(), Account::where('space_id', $this->space->id)->get(), CarbonImmutable::now());
    }

    public function test_month_total_is_calendar_bounded_and_giving_and_goals_are_not_added_twice(): void
    {
        $this->recurring('Example utility');
        $this->recurring('Example charity', ['category' => 'giving', 'amount_cents' => -5000]);
        $this->recurring('Savings transfer', ['category' => 'savings', 'amount_cents' => -30000]);
        $this->record('goal', 'Savings goal', ['monthly_cents' => 30000, 'goal_type' => 'savings']);
        $this->record('budget', 'Giving cap', ['category' => 'giving', 'purpose' => 'spending', 'monthly_cents' => 100000]);
        $report = $this->report();
        $this->assertSame(15000, $report['summary']['known_total_cents']);
        $this->assertCount(4, $report['rows']); // November plus December, not 45-day sum.
        $this->assertFalse($report['coverage']['complete']);
        $this->assertStringContainsString('not an additional allowance', implode(' ', $report['notes']));
    }

    public function test_posted_shares_supersede_processing_and_old_schedule_while_prior_paid_invoice_is_not_due_again(): void
    {
        $schedule = $this->recurring('Sharing contribution', ['category' => 'health', 'amount_cents' => -73500, 'next_due_on' => '2026-09-15']);
        foreach (['2026-10' => 40000, '2026-11' => 60000] as $month => $amount) {
            $tx = $this->transaction('Sharing contribution', $amount, '2026-11-17');
            $this->record('bill_observation', 'Sharing '.$month, ['reference' => $month, 'recurring_record_id' => $schedule->id, 'account_id' => $this->cash->id, 'service_month' => $month, 'amount_cents' => $amount, 'status' => 'processing', 'initiated_on' => '2026-11-16', 'transaction_id' => $tx->id, 'observed_on' => '2026-11-18', 'source' => 'Fictional share receipt and exact posted bank match', 'reviewed' => true, 'future_amount_unknown' => true]);
        }
        $tx = $this->transaction('Power utility', 80000, '2026-10-27');
        $this->record('bill_observation', 'Power invoice', ['reference' => 'power-nov', 'service_month' => '2026-10', 'due_on' => '2026-11-13', 'amount_cents' => 80000, 'status' => 'paid', 'paid_on' => '2026-10-27', 'transaction_id' => $tx->id, 'observed_on' => '2026-11-18', 'source' => 'Fictional invoice and posted payment', 'reviewed' => true]);
        $before = Transaction::get()->map->getRawOriginal()->all();
        $events = Event::count();
        $report = $this->report();
        $this->assertSame(140000, $report['summary']['known_total_cents']);
        $this->assertSame(140000, $report['summary']['paid_cents']);
        $this->assertSame(100000, $report['summary']['bank_cash_paid_cents']);
        $this->assertNull($report['summary']['processing_cents']);
        $this->assertNull($report['summary']['still_due_cents']);
        $this->assertNotContains(73500, array_column($report['rows'], 'amount_cents'));
        $this->assertTrue(collect($report['rows'])->firstWhere('name', 'Sharing 2026-10')['carried_over']);
        $this->assertSame($before, Transaction::get()->map->getRawOriginal()->all());
        $this->assertSame($events, Event::count());
    }

    public function test_pending_exact_payment_is_processing_and_is_not_paid_until_posted(): void
    {
        $this->recurring('Example internet', ['next_due_on' => '2026-11-18']);
        $tx = $this->transaction('Example internet', 10000, '2026-11-18', true);
        $report = $this->report();
        $this->assertSame(10000, $report['summary']['processing_cents']);
        $this->assertNull($report['summary']['paid_cents']);
        $tx->update(['pending' => false]);
        $this->assertSame(10000, $this->report()['summary']['paid_cents']);
    }

    public function test_exact_owned_payment_supplies_account_scope_when_optional_account_fields_are_absent(): void
    {
        $tx = $this->transaction('Example paid invoice', 15000, '2026-11-17');
        $this->record('bill_observation', 'Paid invoice', ['reference' => 'exact-owned', 'obligation_month' => '2026-11', 'amount_cents' => 15000, 'status' => 'paid', 'paid_on' => '2026-11-17', 'transaction_id' => $tx->id, 'observed_on' => '2026-11-18', 'source' => 'Fictional exact bank payment', 'reviewed' => true]);
        $report = app(MonthlyBillsService::class)->build($this->space, Record::where('space_id', $this->space->id)->get(), collect(), Account::where('space_id', $this->space->id)->get(), CarbonImmutable::now(), $this->owner, $this->cash->id);
        $this->assertSame(15000, $report['summary']['bank_cash_paid_cents']);
        $this->assertSame($this->cash->id, $report['rows'][0]['account_id']);
        $this->assertCount(1, $report['rows']);
    }

    public function test_unknown_annual_renewal_is_not_an_annualized_monthly_bill_or_false_zero(): void
    {
        $this->record('subscription', 'Example annual service', ['merchant' => 'Example annual service', 'amount_cents' => 12999, 'cadence' => 'yearly', 'status' => 'active', 'source' => 'Fictional annual receipt', 'observed_on' => '2026-09-01']);
        $report = $this->report();
        $this->assertNull($report['summary']['known_total_cents']);
        $this->assertNull($report['rows'][0]['amount_cents']);
        $this->assertNull($report['rows'][0]['due_on']);
        $this->assertStringContainsString('not divided', implode(' ', $report['rows'][0]['reasons']));
    }

    public function test_lender_notice_replaces_its_schedule_for_that_month_only(): void
    {
        $debt = Account::create(['space_id' => $this->space->id, 'source_key' => 'example:loan', 'name' => 'Example loan', 'kind' => 'loan', 'currency' => 'USD', 'balance_cents' => 500000]);
        $schedule = $this->recurring('Old loan payment');
        $this->record('debt', 'Loan contract', ['account_id' => $debt->id, 'payment_account_id' => $this->cash->id, 'recurring_record_id' => $schedule->id, 'balance_cents' => 500000, 'payment_cents' => 10000, 'next_due_on' => '2026-11-24', 'confirmed' => true]);
        $this->record('payment_notice', 'Current loan notice', ['account_id' => $debt->id, 'payment_account_id' => $this->cash->id, 'amount_cents' => 12000, 'due_on' => '2026-11-24', 'observed_on' => '2026-11-18', 'confirmed' => true, 'source' => 'Fictional lender notice']);
        $report = $this->report();
        $this->assertSame(12000, $report['summary']['known_total_cents']);
        $this->assertCount(2, $report['rows']);
    }

    public function test_related_debt_and_medical_observations_replace_their_exact_month_without_duplicate_plans(): void
    {
        $loan = Account::create(['space_id' => $this->space->id, 'source_key' => 'example:linked-loan', 'name' => 'Example linked loan', 'kind' => 'loan', 'currency' => 'USD', 'balance_cents' => 500000]);
        $debt = $this->record('debt', 'Loan terms', ['account_id' => $loan->id, 'balance_cents' => 500000, 'payment_cents' => 10000, 'next_due_on' => '2026-11-24', 'confirmed' => true]);
        $medical = $this->record('medical_bill', 'Medical plan', ['billed_cents' => 100000, 'payment_cents' => 5000, 'next_due_on' => '2026-11-24', 'confirmed' => true]);
        foreach ([[$debt, 'debt_record_id', 12000], [$medical, 'medical_bill_record_id', 4000]] as [$related, $field, $amount]) {
            $this->record('bill_observation', $related->name.' current bill', ['reference' => $field, $field => $related->id, 'obligation_month' => '2026-11', 'due_on' => '2026-11-24', 'amount_cents' => $amount, 'category' => 'health', 'status' => 'due', 'observed_on' => '2026-11-18', 'source' => 'Fictional current invoice', 'reviewed' => true]);
        }
        $report = $this->report();
        $this->assertSame(16000, $report['summary']['known_total_cents']);
        $this->assertCount(2, collect($report['rows'])->where('in_month', true));
        $this->assertSame(15000, collect($report['rows'])->where('obligation_month', '2026-12')->sum('amount_cents'));
    }

    public function test_lender_minimum_is_reference_only_when_reviewed_weekly_cash_withdrawals_exist(): void
    {
        $loan = Account::create(['space_id' => $this->space->id, 'source_key' => 'example:weekly-loan', 'name' => 'Example weekly loan', 'kind' => 'loan', 'currency' => 'USD', 'balance_cents' => 500000]);
        $debt = $this->record('debt', 'Weekly cash terms', ['account_id' => $loan->id, 'payment_account_id' => $this->cash->id, 'balance_cents' => 500000, 'payment_cents' => 12000, 'next_due_on' => '2026-11-24', 'cash_payment_cents' => 10000, 'cash_next_due_on' => '2026-11-03', 'cash_cadence' => 'weekly', 'confirmed' => true]);
        $this->record('payment_notice', 'Lender monthly minimum', ['account_id' => $loan->id, 'payment_account_id' => $this->cash->id, 'amount_cents' => 12000, 'due_on' => '2026-11-24', 'observed_on' => '2026-11-18', 'confirmed' => true, 'source' => 'Fictional minimum due']);
        $tx = $this->transaction('Loan weekly payment', 10000, '2026-11-17');
        $this->record('bill_observation', 'One paid weekly withdrawal', ['reference' => 'weekly-nov17', 'debt_record_id' => $debt->id, 'obligation_month' => '2026-11', 'due_on' => '2026-11-17', 'amount_cents' => 10000, 'category' => 'debt_payment', 'status' => 'paid', 'paid_on' => '2026-11-17', 'transaction_id' => $tx->id, 'observed_on' => '2026-11-18', 'source' => 'Fictional exact posted payment', 'reviewed' => true]);
        $report = $this->report();
        $this->assertSame(40000, $report['summary']['known_total_cents']);
        $this->assertSame(10000, $report['summary']['paid_cents']);
        $this->assertSame(10000, $report['summary']['still_due_cents']);
        $minimum = collect($report['rows'])->firstWhere('name', 'Lender monthly minimum');
        $this->assertTrue($minimum['reference_only']);
        $this->assertSame(12000, $minimum['amount_cents']);
        $this->assertCount(4, collect($report['rows'])->where('in_month', true)->where('reference_only', false));
    }

    public function test_next_unpaid_anchor_can_include_exact_paid_current_month_without_end_of_month_drift(): void
    {
        $this->recurring('Month end utility', ['next_due_on' => '2026-12-31']);
        $this->transaction('Month end utility', 10000, '2026-11-30');
        $report = app(MonthlyBillsService::class)->build($this->space, Record::where('space_id', $this->space->id)->get(), collect(), Account::where('space_id', $this->space->id)->get(), CarbonImmutable::parse('2026-11-30'));
        $this->assertSame(['2026-11-30', '2026-12-31'], array_column($report['rows'], 'due_on'));
        $this->assertSame(10000, $report['summary']['paid_cents']);
    }

    public function test_processor_text_alone_does_not_mark_a_scheduled_bill_paid(): void
    {
        $this->recurring('PayPal', ['next_due_on' => '2026-11-17']);
        $this->transaction('PayPal', 10000, '2026-11-17');
        $report = $this->report();
        $this->assertNull($report['summary']['paid_cents']);
        $this->assertSame('needs_review', $report['rows'][0]['status']);
        $this->assertStringContainsString('receipt-bound', implode(' ', $report['rows'][0]['reasons']));
    }

    public function test_missing_next_dates_remain_named_unknown_bills_without_zero_or_balance_as_payment(): void
    {
        $this->recurring('Undated service', ['next_due_on' => null]);
        $this->record('debt', 'Undated loan', ['balance_cents' => 500000, 'payment_cents' => 20000, 'confirmed' => true]);
        $report = $this->report();
        $this->assertNull($report['summary']['known_total_cents']);
        $this->assertSame(2, $report['summary']['unknown_amount_count']);
        $this->assertSame(['Undated loan', 'Undated service'], array_column($report['rows'], 'name'));
        $this->assertSame([null, null], array_column($report['rows'], 'amount_cents'));
        $this->assertStringContainsString('outstanding loan balance is not a monthly bill', implode(' ', $report['rows'][0]['reasons']));
    }

    public function test_a_known_due_date_without_a_payment_amount_does_not_imply_zero_still_due(): void
    {
        $this->record('medical_bill', 'Undetermined plan payment', ['billed_cents' => 100000, 'next_due_on' => '2026-11-24', 'confirmed' => true]);
        $report = $this->report();
        $this->assertNull($report['summary']['known_total_cents']);
        $this->assertNull($report['summary']['still_due_cents']);
        $this->assertSame(1, $report['summary']['unknown_amount_count']);
        $this->assertNull($report['rows'][0]['amount_cents']);
    }

    public function test_foreign_currency_bill_is_not_added_to_the_native_currency_total(): void
    {
        $foreign = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:foreign-cash', 'name' => 'Example euro cash', 'kind' => 'cash', 'currency' => 'EUR']);
        $this->recurring('Local utility');
        $this->recurring('Euro utility', ['account_id' => $foreign->id, 'amount_cents' => -50000]);
        $report = $this->report();
        $this->assertSame(10000, $report['summary']['known_total_cents']);
        $this->assertStringContainsString('not converted', implode(' ', $report['coverage']['reasons']));
        $this->assertSame('EUR', collect($report['rows'])->firstWhere('name', 'Euro utility')['currency']);
    }

    public function test_card_funded_service_and_its_card_payment_do_not_both_increase_cash_bills(): void
    {
        $credit = Account::create(['space_id' => $this->space->id, 'source_key' => 'fictional:credit', 'name' => 'Example card', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 50000]);
        $this->recurring('Local cash bill', ['amount_cents' => -20000]);
        $this->recurring('Example service', ['account_id' => $credit->id, 'category' => 'subscriptions', 'amount_cents' => -1000, 'next_due_on' => '2026-11-17']);
        app(LedgerService::class)->ingest($credit, [['id' => 'service-charge', 'date' => '2026-11-17', 'merchant' => 'Example service', 'amount_cents' => -1000]]);
        $this->record('payment_notice', 'Card minimum', ['account_id' => $credit->id, 'payment_account_id' => $this->cash->id, 'amount_cents' => 3000, 'due_on' => '2026-11-28', 'observed_on' => '2026-11-18', 'confirmed' => true, 'source' => 'Fictional card statement']);
        $report = $this->report();
        $this->assertSame(23000, $report['summary']['known_total_cents']);
        $this->assertSame(1000, $report['summary']['card_funded_cents']);
        $this->assertNull($report['summary']['bank_cash_paid_cents']);
        $this->assertTrue(collect($report['rows'])->firstWhere('name', 'Example service')['card_funded']);
    }

    public function test_other_household_bank_evidence_cannot_mark_an_owned_bill_paid(): void
    {
        $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
        $otherCash = Account::create(['space_id' => $otherSpace->id, 'source_key' => 'fictional:private-cash', 'name' => 'Other cash', 'kind' => 'cash', 'currency' => 'USD']);
        app(LedgerService::class)->ingest($otherCash, [['id' => 'other-payment', 'date' => '2026-11-17', 'merchant' => 'Private other household merchant', 'amount_cents' => -10000]]);
        $tx = Transaction::where('space_id', $otherSpace->id)->firstOrFail();
        $this->record('bill_observation', 'Owned bill with invalid match', ['reference' => 'wrong-match', 'service_month' => '2026-11', 'amount_cents' => 10000, 'status' => 'paid', 'transaction_id' => $tx->id, 'source' => 'Fictional input requiring review', 'observed_on' => '2026-11-18', 'reviewed' => true]);
        $report = $this->report();
        $this->assertSame('needs_review', $report['rows'][0]['status']);
        $this->assertSame([], $report['rows'][0]['evidence_ids']);
        $this->assertStringNotContainsString('Private other household merchant', json_encode($report));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MonthlyBillsService::class)->build($this->space, collect(), collect(), collect(), CarbonImmutable::now(), $other);
    }

    public function test_native_bill_evidence_is_versioned_and_idempotent_without_creating_or_changing_bank_activity(): void
    {
        $transaction = $this->transaction('Example bill', 10000, '2026-11-17');
        $before = $transaction->getRawOriginal();
        $count = Transaction::count();
        $data = ['reference' => 'fictional-current-invoice', 'obligation_month' => '2026-11', 'category' => 'utilities', 'amount_cents' => 10000,
            'status' => 'paid', 'due_on' => '2026-11-24', 'paid_on' => '2026-11-17', 'payment_account_id' => $this->cash->id,
            'transaction_id' => $transaction->id, 'observed_on' => '2026-11-18', 'source' => 'Fictional invoice and matching posted bank payment', 'reviewed' => true];
        $service = app(RecordService::class);
        $record = $service->save($this->owner, $this->space, 'bill_observation', 'Example invoice', $data);
        $replay = $service->save($this->owner, $this->space, 'bill_observation', 'Example invoice', $data);
        $this->assertSame($record->id, $replay->id);
        $this->assertSame(1, Record::where('kind', 'bill_observation')->count());
        $this->assertSame(1, Event::where('action', 'save_bill_observation')->count());
        $this->assertSame($count, Transaction::count());
        $this->assertSame($before, $transaction->fresh()->getRawOriginal());
        $versioned = $service->save($this->owner, $this->space, 'bill_observation', 'Corrected invoice name', $data, $record, 1);
        $this->assertSame(2, $versioned->version);
        try {
            $service->save($this->owner, $this->space, 'bill_observation', 'Stale invoice edit', $data, $record, 1);
            $this->fail('A stale bill version must not save.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_bill_admission_rejects_wrong_month_and_paid_pending_bank_evidence(): void
    {
        $transaction = $this->transaction('Pending invoice', 10000, '2026-11-18', true);
        $data = ['reference' => 'fictional-pending-invoice', 'obligation_month' => '2026-11', 'category' => 'utilities', 'amount_cents' => 10000,
            'status' => 'paid', 'paid_on' => '2026-11-18', 'transaction_id' => $transaction->id,
            'observed_on' => '2026-11-18', 'source' => 'Fictional source', 'reviewed' => true];
        foreach ([$data, [...$data, 'status' => 'processing', 'initiated_on' => '2026-11-18', 'due_on' => '2026-12-01']] as $invalid) {
            try {
                app(RecordService::class)->save($this->owner, $this->space, 'bill_observation', 'Invalid invoice', $invalid);
                $this->fail('Unposted paid evidence or a contradictory obligation month must fail.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
        $this->assertSame(0, Record::where('kind', 'bill_observation')->count());
        $this->assertTrue($transaction->fresh()->pending);
        $this->assertSame(1, Transaction::count());
    }
}
