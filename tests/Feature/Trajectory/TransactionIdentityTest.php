<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use App\Services\Trajectory\SalesTaxService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2028-02-18', 'UTC'));
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->household] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->household->update(['timezone' => 'UTC']);
    $this->tenant = Tenant::create(['slug' => 'identity-fixture', 'name' => 'Fictional Studio']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($this->tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $this->business] = app(PilotService::class)->prepare($this->owner, $this->tenant, true);
    $this->business->update(['name' => 'Fictional Studio', 'timezone' => 'UTC']);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $this->account = Account::create(['space_id' => $this->business->id, 'source_key' => 'identity:cash', 'name' => 'Fictional operating account (…1527)', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2028-01-01']);
    app(LedgerService::class)->ingest($this->account, [['id' => 'shared-cost', 'date' => '2028-02-16', 'merchant' => 'Fictional Market', 'amount_cents' => -20000, 'category' => 'shopping']]);
    $this->tx = Transaction::where('account_id', $this->account->id)->firstOrFail();
    app(AttributionService::class)->save($this->owner, $this->business, $this->tx, [
        ['space_id' => $this->household->id, 'amount_cents' => -12000, 'category' => 'groceries'],
        ['space_id' => $this->business->id, 'amount_cents' => -8000, 'category' => 'materials'],
    ], $this->tx->version);
    $this->cardInput = fn () => ['transaction_id' => $this->tx->id, 'card_last4' => '7851', 'card_type' => 'debit', 'card_label' => 'Fictional staff card',
        'observed_on' => '2028-02-18', 'source' => 'Fictional verified receipt', 'reviewed' => true,
        'expected_bank_source_fingerprint' => app(SalesTaxService::class)->bankFingerprint($this->business->id, $this->account->id, $this->tx->id, '2028-02-16', -20000, 'Fictional Market')];
});

test('authorized shared source identity shows merchant and paying business without changing allocated or bank amounts', function (): void {
    $before = $this->tx->refresh()->getAttributes();
    $entry = app(LedgerService::class)->entries($this->household, viewer: $this->owner)[0];
    expect($entry['merchant'])->toBe('Fictional Market')->and($entry['amount_cents'])->toBe(-12000)
        ->and($entry['account_id'])->toBeNull()->and($entry['editable'])->toBeFalse()
        ->and($entry['source_identity']['paid_by_label'])->toBe('Household purchase paid by Fictional Studio')
        ->and($entry['source_identity']['account_name'])->toBe('Fictional operating account (…1527)')
        ->and($entry['source_identity']['card_last4'])->toBeNull()
        ->and($entry['source_identity']['card_evidence_status'])->toBe('not_recorded')
        ->and($this->tx->fresh()->getAttributes())->toBe($before);
    $this->actingAs($this->owner)->postJson('/trajectory/spaces/'.$this->household->id.'/evidence', ['ids' => [$this->tx->id]])
        ->assertOk()->assertJsonPath('0.merchant', 'Fictional Market')->assertJsonPath('0.amount_cents', -12000);
});

test('source permission revocation removes real merchant account and physical card details on the next read', function (): void {
    app(RecordService::class)->save($this->owner, $this->business, 'transaction_source_identity', 'Fictional card evidence', ($this->cardInput)());
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $entry = app(LedgerService::class)->entries($this->household, viewer: $this->owner)[0];
    expect($entry['merchant'])->toBe('Household purchase paid from another space')
        ->and($entry['source_identity']['authorized_source'])->toBeFalse()
        ->and($entry['source_identity']['account_name'])->toBeNull()->and($entry['source_identity']['card_last4'])->toBeNull()
        ->and(json_encode($entry))->not->toContain('Fictional Market', 'Fictional Studio', '7851', '1527')
        ->and($entry['amount_cents'])->toBe(-12000);
});

test('physical card details require explicit current evidence rather than bank masks and stale duplicates are held', function (): void {
    $record = app(RecordService::class)->save($this->owner, $this->business, 'transaction_source_identity', 'Fictional card evidence', ($this->cardInput)());
    $entry = app(LedgerService::class)->entries($this->household, viewer: $this->owner)[0];
    expect($entry['source_identity']['card_last4'])->toBe('7851')->and($entry['source_identity']['card_type'])->toBe('debit');
    $this->tx->update(['merchant' => 'Amended fictional source']);
    $entry = app(LedgerService::class)->entries($this->household, viewer: $this->owner)[0];
    expect($entry['source_identity']['card_last4'])->toBeNull()->and($entry['source_identity']['card_evidence_status'])->toBe('needs_review');
    $this->tx->update(['merchant' => 'Fictional Market']);
    $record->replicate()->save();
    $entry = app(LedgerService::class)->entries($this->household, viewer: $this->owner)[0];
    expect($entry['source_identity']['card_last4'])->toBeNull()->and($entry['source_identity']['card_evidence_status'])->toBe('needs_review');
});

test('card evidence validates exact digits source space current bank facts and actual observation dates', function (): void {
    $service = app(RecordService::class);
    expect(fn () => $service->validate($this->household, 'transaction_source_identity', ($this->cardInput)()))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    foreach ([['card_last4' => '15271234'], ['card_last4' => '1x27']] as $invalid) {
        expect(fn () => $service->validate($this->business, 'transaction_source_identity', [...($this->cardInput)(), ...$invalid]))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    foreach ([['observed_on' => '2028-02-19'], ['observed_on' => '2028-02-15']] as $invalid) {
        expect(fn () => $service->validate($this->business, 'transaction_source_identity', [...($this->cardInput)(), ...$invalid]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    expect(fn () => $service->validate($this->business, 'transaction_source_identity', [...($this->cardInput)(), 'expected_bank_source_fingerprint' => str_repeat('a', 64)]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
