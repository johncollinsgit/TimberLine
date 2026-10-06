<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\SourceCoverageReconciliationService;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'live-usaa', 'access_token' => 'fixture']);
    $this->monarch = Account::create(['space_id' => $this->space->id, 'source_key' => 'monarch:'.$this->space->id.':fixture', 'name' => 'Main Account (...3129)', 'kind' => 'cash']);
    $this->plaid = Account::create(['space_id' => $this->space->id, 'connection_id' => $this->connection->id, 'source_key' => 'plaid:'.$this->connection->id.':main', 'name' => 'MAIN ACCOUNT', 'kind' => 'cash']);
    app(LedgerService::class)->ingest($this->monarch, [
        ['id' => 'old', 'date' => '2025-01-01', 'merchant' => 'Before live coverage', 'amount_cents' => -1000, 'source_provider' => 'monarch_csv', 'import_classification' => ['category' => 'uncategorized', 'flow' => 'expense', 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Historical import.']],
        ['id' => 'same', 'date' => '2025-03-01', 'merchant' => 'Merchant', 'amount_cents' => -2000, 'source_provider' => 'monarch_csv', 'import_classification' => ['category' => 'uncategorized', 'flow' => 'expense', 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Historical import.']],
        ['id' => 'statement-date-difference', 'date' => '2025-03-02', 'merchant' => 'Merchant with older statement date', 'amount_cents' => -3000, 'source_provider' => 'monarch_csv', 'import_classification' => ['category' => 'uncategorized', 'flow' => 'expense', 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Historical import.']],
    ]);
    app(LedgerService::class)->ingest($this->plaid, [
        ['id' => 'same', 'date' => '2025-03-01', 'merchant' => 'Merchant', 'amount_cents' => -2000],
        ['id' => 'different-date', 'date' => '2025-03-03', 'merchant' => 'Merchant', 'amount_cents' => -3000],
    ]);
});

test('live Plaid coverage supersedes the same Monarch account after its coverage cutoff without deleting evidence', function (): void {
    $service = app(SourceCoverageReconciliationService::class);
    $preview = $service->preview($this->owner, $this->space);
    expect($preview)->toHaveCount(1)
        ->and($preview[0])->toMatchArray(['account_name' => 'MAIN ACCOUNT', 'cutover_on' => '2025-03-01', 'keep_before_cutover' => 1, 'supersede_count' => 2]);

    $applied = $service->apply($this->owner, $this->space);
    expect($applied)->toHaveCount(1)->and($applied[0]['superseded_count'])->toBe(2);
    $hidden = Transaction::where('account_id', $this->monarch->id)->where('removed', true)->get();
    expect($hidden)->toHaveCount(2)
        ->and($hidden->every(fn (Transaction $row): bool => isset($row->source['source_superseded_by'])))->toBeTrue();
    expect(collect(app(LedgerService::class)->entries($this->space))->pluck('id'))->toHaveCount(3);
    expect(Event::where('action', 'source_coverage_superseded')->count())->toBe(1);

    app(LedgerService::class)->ingest($this->monarch, [[
        'id' => 'same', 'date' => '2025-03-01', 'merchant' => 'Merchant', 'amount_cents' => -2000,
        'source_provider' => 'monarch_csv',
        'import_classification' => ['category' => 'uncategorized', 'flow' => 'expense', 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Historical import.'],
    ]]);
    expect(Transaction::where('account_id', $this->monarch->id)->where('removed', true)->count())->toBe(2)
        ->and(Event::where('action', 'source_coverage_superseded')->count())->toBe(1);
});

test('source coverage reconciliation is reversible and leaves unmatched account names untouched', function (): void {
    $other = Account::create(['space_id' => $this->space->id, 'source_key' => 'monarch:'.$this->space->id.':other', 'name' => 'Different historical account', 'kind' => 'cash']);
    app(LedgerService::class)->ingest($other, [['id' => 'only-history', 'date' => '2025-03-02', 'merchant' => 'Keep me', 'amount_cents' => -4000, 'source_provider' => 'monarch_csv', 'import_classification' => ['category' => 'uncategorized', 'flow' => 'expense', 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Historical import.']]]);
    $service = app(SourceCoverageReconciliationService::class);
    $service->apply($this->owner, $this->space);
    expect(Transaction::where('account_id', $other->id)->sole()->removed)->toBeFalse();
    expect($service->restore($this->owner, $this->space))->toBe(2);
    expect(Transaction::where('account_id', $this->monarch->id)->where('removed', false)->count())->toBe(3);
    expect(Event::where('action', 'source_coverage_restored')->count())->toBe(1);
});

test('the command previews applies and restores only the authorized household', function (): void {
    $command = 'trajectory:reconcile-import-sources --owner='.$this->owner->email.' --space='.$this->space->id.' --json';
    $this->artisan($command)->assertSuccessful();
    expect(Transaction::where('account_id', $this->monarch->id)->where('removed', true)->count())->toBe(0);
    $this->artisan($command.' --apply')->assertSuccessful();
    expect(Transaction::where('account_id', $this->monarch->id)->where('removed', true)->count())->toBe(2);
    $this->artisan($command.' --restore')->assertSuccessful();
    expect(Transaction::where('account_id', $this->monarch->id)->where('removed', false)->count())->toBe(3);
});
