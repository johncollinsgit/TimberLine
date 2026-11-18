<?php

namespace Tests\Feature\Trajectory;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AnalysisService;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\RecordService;
use App\Services\Trajectory\SalesTaxService;
use App\Services\Trajectory\TransactionIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingSelectedIdentityDecorator extends TransactionIdentityService
{
    public array $calls = [];

    public function decorate(User $viewer, \App\Models\Trajectory\Space $space, array $entries): array
    {
        $this->calls[] = array_column($entries, 'id');

        return parent::decorate($viewer, $space, $entries);
    }
}

class AnalysisSelectedIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $space;

    private Account $cash;

    private RecordingSelectedIdentityDecorator $identity;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
        $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
        $this->space->update(['timezone' => 'UTC']);
        $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'identity-perf:cash', 'name' => 'Fictional checking', 'kind' => 'cash', 'currency' => 'USD', 'history_start' => '2024-01-01', 'observed_at' => now(), 'balance_cents' => 1000000]);
        $this->identity = new RecordingSelectedIdentityDecorator;
        app()->instance(TransactionIdentityService::class, $this->identity);
    }

    public function test_selected_identity_batch_excludes_comparison_history_and_preserves_full_dto(): void
    {
        $history = [];
        for ($i = 0; $i < 500; $i++) {
            $history[] = ['id' => 'history-'.$i, 'date' => '2025-11-10', 'merchant' => 'Fictional history '.$i, 'amount_cents' => -100, 'category' => 'shopping'];
        }
        app(LedgerService::class)->ingest($this->cash, $history);
        $current = $this->add($this->cash, 'current', '2026-11-17', -7000);
        $credit = Account::create(['space_id' => $this->space->id, 'source_key' => 'identity-perf:credit', 'name' => 'Fictional credit', 'kind' => 'credit', 'currency' => 'USD', 'history_start' => '2024-01-01', 'observed_at' => now(), 'balance_cents' => 10000]);
        $this->add($credit, 'current-credit', '2026-11-17', -3000);
        $this->add($this->cash, 'income', '2026-11-17', 10000);
        $before = $this->snapshot();
        foreach (['spending', 'cash'] as $basis) {
            $options = ['from' => '2026-11-01', 'through' => '2026-11-18', 'year' => 2026, 'basis' => $basis];
            $this->identity->calls = [];
            $new = app(AnalysisService::class)->build($this->owner, $this->space, $options);
            $this->assertSame(10000, $new['summary']['inflow_cents']);
            $this->assertSame($basis === 'cash' ? 7000 : 10000, $new['summary']['spending_cents']);
            $this->assertSame(50000, $new['periods'][10]['comparison']['spending_cents']);
            $this->assertCount(1, $this->identity->calls);
            $this->assertCount(3, $this->identity->calls[0]);
            $this->assertContains($current->id, $this->identity->calls[0]);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_shared_source_card_and_revoked_permission_are_identical_after_deferral(): void
    {
        $tenant = Tenant::create(['slug' => 'identity-perf-business', 'name' => 'Fictional private studio']);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
        $account = Account::create(['space_id' => $business->id, 'source_key' => 'identity-perf:business', 'name' => 'Private studio bank (...1527)', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000]);
        $tx = $this->add($account, 'shared', '2026-11-17', -20000);
        app(AttributionService::class)->save($this->owner, $business, $tx, [['space_id' => $this->space->id, 'category' => 'groceries', 'amount_cents' => -12000], ['space_id' => $business->id, 'category' => 'materials', 'amount_cents' => -8000]], $tx->version);
        app(RecordService::class)->save($this->owner, $business, 'transaction_source_identity', 'Fictional card evidence', ['transaction_id' => $tx->id, 'card_last4' => '7851', 'card_type' => 'debit', 'card_label' => 'Private card', 'observed_on' => '2026-11-18', 'source' => 'Fictional receipt', 'reviewed' => true,
            'expected_bank_source_fingerprint' => app(SalesTaxService::class)->bankFingerprint($business->id, $account->id, $tx->id, '2026-11-17', -20000, $tx->merchant)]);
        $numeric = null;
        foreach ([false, true] as $revoked) {
            if ($revoked) {
                $this->owner->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
            }
            $new = app(AnalysisService::class)->build($this->owner, $this->space);
            $totals = [$new['summary'], $new['periods'], array_column($new['categories'], 'amount_cents', 'key')];
            if ($numeric !== null) {
                $this->assertSame($numeric, $totals);
            }
            $numeric = $totals;
            $this->assertSame(12000, $new['summary']['spending_cents']);
            $entry = collect($new['entries'])->firstWhere('id', $tx->id);
            $this->assertSame(! $revoked, $entry['source_identity']['authorized_source']);
            if ($revoked) {
                $this->assertStringNotContainsString('7851', json_encode($new));
            } else {
                $this->assertSame('7851', $entry['source_identity']['card_last4']);
            }
        }
    }

    public function test_empty_selected_period_does_not_decorate_historical_identities(): void
    {
        $this->add($this->cash, 'historical-only', '2025-11-10', -1000);
        $options = ['from' => '2026-10-01', 'through' => '2026-10-31'];
        $this->identity->calls = [];
        $new = app(AnalysisService::class)->build($this->owner, $this->space, $options);
        $this->assertSame(0, $new['summary']['spending_cents']);
        $this->assertSame([], $this->identity->calls);
    }

    private function add(Account $account, string $key, string $date, int $amount): Transaction
    {
        app(LedgerService::class)->ingest($account, [['id' => $key, 'date' => $date, 'merchant' => 'Fictional '.$key, 'amount_cents' => $amount, 'category' => $amount > 0 ? 'income' : 'shopping']]);

        return Transaction::where('source_key', $account->source_key.':'.$key)->firstOrFail();
    }

    private function snapshot(): array
    {
        return [Transaction::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(), Event::count()];
    }
}
