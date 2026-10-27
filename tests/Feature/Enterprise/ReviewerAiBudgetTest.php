<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Trial\TrialBudget;
use App\Enterprise\Trial\TrialStore;
use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiBudget;
use App\Proposals\ReviewerAiStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerAiFixture;

final class ReviewerAiBudgetTest extends ReviewerAiFixture
{
    private function denied(callable $call, int $status = 503): void
    {
        try {
            $call();
            $this->fail('The changed or missing reviewer campaign authority must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function test_owner_adapter_reuses_one_budget_without_legacy_flags_users_or_grants(): void
    {
        $budget = app(ReviewerAiBudget::class);
        $this->assertSame(5000000, $budget->status()['remaining_microusd']);
        $first = $budget->reserve('ai', 'synthetic-reviewer-one', str_repeat('b', 64), 2000000);
        $this->assertFalse($first['replayed']);
        $this->assertSame(3000000, $budget->status()['remaining_microusd']);
        $this->denied(fn () => $budget->reserve('ai', 'synthetic-reviewer-two', str_repeat('c', 64), 3000001), 429);
        $budget->claimDispatch($first['id'], str_repeat('b', 64));
        $budget->settle($first['id'], 'accepted', ['provider_id' => 'resp_synthetic_only'], 100000);
        $this->assertSame(4900000, $budget->status()['remaining_microusd']);
        $replay = $budget->reserve('ai', 'synthetic-reviewer-one', str_repeat('b', 64), 2000000);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
        foreach (['enterprise.enabled', 'enterprise.writes_enabled', 'enterprise.trial.enabled', 'enterprise.conversation.enabled', 'service_proposals.writes_enabled'] as $flag) {
            $this->assertFalse(config($flag), $flag);
        }
        $this->assertInstanceOf(TrialStore::class, app(TrialStore::class));
        $this->assertNotInstanceOf(ReviewerAiStore::class, app(TrialStore::class));
        $this->denied(fn () => app(TrialBudget::class)->status(), 404);
        foreach (DB::table('team_operation_revisions')->where('record_id', $first['id'])->get() as $audit) {
            $this->assertNull($audit->user_id);
            $payload = json_decode(Crypt::decryptString($audit->payload), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('proposal_reviewer_ai', $payload['actor_type']);
            $this->assertStringNotContainsString('api_key', json_encode($payload));
        }
    }

    public function test_pause_uncertainty_and_changed_replay_never_refund_or_create_a_new_allowance(): void
    {
        $budget = app(ReviewerAiBudget::class);
        $charge = $budget->reserve('ai', 'synthetic-unknown', str_repeat('b', 64), 1000000);
        $this->denied(fn () => $budget->reserve('ai', 'synthetic-unknown', str_repeat('c', 64), 1000000), 409);
        $budget->claimDispatch($charge['id'], str_repeat('b', 64));
        $budget->settle($charge['id'], 'uncertain', ['status' => 'unknown']);
        $budget->pause();
        $status = $budget->status();
        $this->assertTrue($status['paused']);
        $this->assertSame(4000000, $status['remaining_microusd']);
        $this->denied(fn () => $budget->reserve('ai', 'new-key-cannot-reset', str_repeat('c', 64), 1));
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(1000000, TeamOperationRecord::query()->findOrFail($charge['id'])->data['charged_microusd']);
    }

    public static function deletedLedgerOperations(): array
    {
        return array_map(fn ($operation) => [$operation], ['status', 'reserve', 'replay', 'claim', 'settle_accepted', 'settle_uncertain', 'pause']);
    }

    #[DataProvider('deletedLedgerOperations')]
    public function test_every_inherited_path_refuses_deleted_ledger_between_claim_and_settlement_or_cleanup(string $operation): void
    {
        $budget = app(ReviewerAiBudget::class);
        $charge = $budget->reserve('ai', 'synthetic-exact-key', str_repeat('b', 64), 100000);
        $budget->claimDispatch($charge['id'], str_repeat('b', 64));
        if ($operation === 'replay') {
            $budget->settle($charge['id'], 'accepted', ['provider_id' => 'resp_synthetic'], 1000);
        }
        DB::table('team_operation_records')->where('id', $this->ledger->id)->delete();
        $before = DB::table('team_operation_records')->where('id', $charge['id'])->first();
        $auditCount = DB::table('team_operation_revisions')->count();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->denied(fn () => match ($operation) {
            'status' => $budget->status(),
            'reserve' => $budget->reserve('ai', 'new-key', str_repeat('c', 64), 1),
            'replay' => $budget->reserve('ai', 'synthetic-exact-key', str_repeat('b', 64), 100000),
            'claim' => $budget->claimDispatch($charge['id'], str_repeat('b', 64)),
            'settle_accepted' => $budget->settle($charge['id'], 'accepted', ['provider_id' => 'resp_synthetic'], 1000),
            'settle_uncertain' => $budget->settle($charge['id'], 'uncertain', ['status' => 'unknown']),
            'pause' => $budget->pause(),
        });
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $this->assertEquals($before, DB::table('team_operation_records')->where('id', $charge['id'])->first());
        $this->assertSame($auditCount, DB::table('team_operation_revisions')->count());
    }

    public function test_replaced_duplicate_and_changed_baseline_cap_anchor_or_pin_deny_without_reset(): void
    {
        $budget = app(ReviewerAiBudget::class);
        $original = config('service_proposals.reviewer_ai');
        foreach (['ledger_id' => (string) Str::uuid(), 'tenant_id' => 99, 'area_id' => (string) Str::uuid(),
            'prior_spend_verified' => false, 'prior_spend_microusd' => 1, 'prior_spend_evidence_hash' => str_repeat('b', 64),
            'inventory_evidence_hash' => str_repeat('c', 64)] as $key => $value) {
            config(['service_proposals.reviewer_ai' => array_replace($original, [$key => $value])]);
            $this->denied(fn () => $budget->status());
        }
        config(['service_proposals.reviewer_ai' => $original]);
        $data = $this->ledger->data;
        $this->ledger->data = array_replace($data, ['cap_microusd' => 10000000]);
        $this->ledger->save();
        $this->denied(fn () => $budget->status());
        $this->ledger->data = $data;
        $this->ledger->save();
        $duplicate = $this->ledger->replicate();
        $duplicate->id = (string) Str::uuid();
        $duplicate->source_key = hash('sha256', 'different-campaign-is-not-another-cap');
        $duplicate->save();
        $this->denied(fn () => $budget->status());
        $this->ledger->delete();
        $this->denied(fn () => $budget->status());
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $this->assertSame($duplicate->id, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->value('id'));
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_default_off_wrong_purpose_and_accidental_anchor_membership_do_not_enable_native_access(): void
    {
        $budget = app(ReviewerAiBudget::class);
        config(['service_proposals.reviewer_ai.enabled' => false]);
        $this->denied(fn () => $budget->status(), 404);
        config(['service_proposals.reviewer_ai.enabled' => true, 'service_proposals.runtime_purpose' => 'campus']);
        $this->denied(fn () => $budget->status(), 404);
        config(['service_proposals.runtime_purpose' => 'proposal_owner']);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => $this->anchor->id, 'user_id' => 999, 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $this->denied(fn () => $budget->status());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
    }

    public function test_explicit_wrong_ledger_and_other_channels_deny_and_overrun_freezes_original_campaign(): void
    {
        $budget = app(ReviewerAiBudget::class);
        $this->denied(fn () => $budget->reserve('sms', 'not-authorized', str_repeat('b', 64), 100), 422);
        $this->denied(fn () => $budget->reserve('email', 'not-authorized', str_repeat('b', 64), 100), 422);
        $charge = $budget->reserve('ai', 'synthetic-overrun', str_repeat('b', 64), 100000);
        $budget->claimDispatch($charge['id'], str_repeat('b', 64));
        $before = DB::table('team_operation_records')->where('id', $charge['id'])->first();
        $this->denied(fn () => $budget->settle($charge['id'], 'accepted', [], 1, (string) Str::uuid()));
        $this->denied(fn () => $budget->pause((string) Str::uuid()));
        $this->assertEquals($before, DB::table('team_operation_records')->where('id', $charge['id'])->first());
        $this->denied(fn () => $budget->settle($charge['id'], 'accepted', [], 100001));
        $this->assertTrue($budget->status()['paused']);
        $this->assertSame(100001, TeamOperationRecord::query()->findOrFail($charge['id'])->data['charged_microusd']);
        $this->assertSame('uncertain', TeamOperationRecord::query()->findOrFail($charge['id'])->status);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
    }

    public function test_runtime_store_cannot_initialize_ledger_or_mutate_foreign_rows_and_rolls_back_failed_charge(): void
    {
        $store = app(ReviewerAiStore::class);
        $this->denied(fn () => $store->create('ent_trial_budget', 'replacement', [], 'active'));
        $foreign = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => '73000000-0000-4000-8000-000000000001', 'kind' => 'ent_trial_charge', 'title' => 'Private owner sentinel',
            'source_key' => hash('sha256', 'foreign'), 'data' => ['reserved_microusd' => 1]]);
        $raw = DB::table('team_operation_records')->where('id', $foreign->id)->value('data');
        $this->denied(fn () => $store->save($foreign, ['changed' => true]));
        $this->denied(fn () => $store->audit($foreign, 'invalid'));
        $this->assertSame($raw, DB::table('team_operation_records')->where('id', $foreign->id)->value('data'));
        TeamOperationRecord::created(function ($row): void {
            if ($row->kind === 'ent_trial_charge') {
                throw new \RuntimeException('Synthetic late write failure');
            }
        });
        try {
            app(ReviewerAiBudget::class)->reserve('ai', 'must-roll-back', str_repeat('b', 64), 100);
            $this->fail('Synthetic late failure must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic late write failure', $exception->getMessage());
        } finally {
            TeamOperationRecord::flushEventListeners();
        }
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
    }
}
