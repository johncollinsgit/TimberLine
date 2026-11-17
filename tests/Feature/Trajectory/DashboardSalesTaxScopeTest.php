<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use App\Services\Trajectory\SalesTaxService;

test('dashboard account scope retains its bank tax evidence without exposing other account or shared source notes', function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2028-07-25', 'UTC'));
    config(['trajectory.enabled' => true]);
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$space] = app(PilotService::class)->prepare($owner, null, true);
    $otherOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$foreignSpace] = app(PilotService::class)->prepare($otherOwner, null, true);

    $taxPayment = function ($user, $targetSpace, string $key, int $amount, string $privateSource): array {
        $account = Account::create(['space_id' => $targetSpace->id, 'name' => $key, 'source_key' => 'dashboard-tax:'.$key, 'kind' => 'cash', 'balance_cents' => 50000]);
        app(LedgerService::class)->ingest($account, [['id' => 'payment', 'date' => '2028-07-20', 'merchant' => 'Example State Revenue', 'amount_cents' => -$amount]]);
        $tx = Transaction::where('account_id', $account->id)->firstOrFail();
        $record = app(RecordService::class)->save($user, $targetSpace, 'tax_remittance', $key.' filing', [
            'transaction_id' => $tx->id, 'paid_on' => '2028-07-20', 'amount_cents' => -$amount,
            'agency' => 'Example revenue agency', 'tax_type' => 'sales_tax', 'movement' => 'payment',
            'source' => $privateSource, 'reviewed' => true,
            'expected_bank_source_fingerprint' => app(SalesTaxService::class)->bankFingerprint($targetSpace->id, $account->id, $tx->id, '2028-07-20', -$amount, 'Example State Revenue'),
        ]);

        return [$account, $tx, $record];
    };
    [$selected, $selectedTx, $selectedRecord] = $taxPayment($owner, $space, 'selected cash', 12500, 'Selected account filing receipt');
    [$unselected] = $taxPayment($owner, $space, 'unselected cash', 22000, 'Other account private filing receipt');
    [, $foreignTx] = $taxPayment($otherOwner, $foreignSpace, 'foreign cash', 19000, 'Foreign source private filing receipt');
    // A legitimate shared amount reveals no source account, merchant or filing.
    Allocation::where('transaction_id', $foreignTx->id)->update(['amount_cents' => -18000]);
    Allocation::create(['transaction_id' => $foreignTx->id, 'space_id' => $space->id, 'amount_cents' => -1000]);

    $url = '/trajectory/spaces/'.$space->id.'/dashboard?range=custom&from=2028-07-01&through=2028-07-25';
    $all = $this->actingAs($owner)->getJson($url)->assertOk();
    $all->assertJsonPath('sales_tax.confirmed_payment_cents', 34500)->assertJsonCount(2, 'sales_tax.rows');

    $scoped = $this->getJson($url.'&account_id='.$selected->id)->assertOk();
    $scoped->assertJsonPath('sales_tax.confirmed_payment_cents', 12500)
        ->assertJsonCount(1, 'sales_tax.rows')
        ->assertJsonPath('sales_tax.rows.0.transaction_id', $selectedTx->id)
        ->assertJsonPath('sales_tax.rows.0.record_id', $selectedRecord->id)
        ->assertJsonPath('sales_tax.rows.0.reviewed', true)
        ->assertJsonPath('sales_tax.rows.0.account_id', $selected->id);
    expect($scoped->getContent())->toContain('Selected account filing receipt')
        ->not->toContain('Other account private filing receipt', 'Foreign source private filing receipt');

    $other = $this->getJson($url.'&account_id='.$unselected->id)->assertOk();
    $other->assertJsonPath('sales_tax.confirmed_payment_cents', 22000)->assertJsonCount(1, 'sales_tax.rows');
    expect($other->getContent())->not->toContain('Selected account filing receipt', 'Foreign source private filing receipt');
});
