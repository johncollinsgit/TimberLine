<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiBudget;
use App\Proposals\ReviewerAiReceipts;
use App\Proposals\ReviewerAiSnapshot;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerAiConnectionFixture;

final class ReviewerAiReceiptTest extends ReviewerAiConnectionFixture
{
    private function snapshot(): array
    {
        return ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 12,
            'area_ids' => ['73000000-0000-4000-8000-000000000001'], 'sources' => [['ref' => 'project:75000000-0000-4000-8000-000000000001',
                'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'Fictional job', 'version' => 1,
                'status' => 'active', 'tasks' => [], 'buildings' => ['Fictional building']]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
    }

    private function receipt(array $scope, array $preview): array
    {
        return ['preview_id' => $preview['id'], 'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest'], 'request_id' => $preview['request_id'],
            'binding' => ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']]];
    }

    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('The changed/expired/private receipt must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function test_preview_is_encrypted_exact_bounded_idempotent_and_does_not_reserve_cost_or_expose_native_owner_data(): void
    {
        $scope = $this->configuredConnection();
        $receipts = app(ReviewerAiReceipts::class);
        $key = (string) Str::uuid();
        $preview = $receipts->prepare($scope, 'PRIVATE QUESTION SENTINEL', $this->snapshot(), $key);
        $this->assertSame($preview, $receipts->prepare($scope, 'PRIVATE QUESTION SENTINEL', $this->snapshot(), $key));
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', ReviewerAiReceipts::KIND)->count());
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $raw = DB::table('team_operation_records')->where('id', $preview['id'])->value('data');
        $this->assertStringNotContainsString('PRIVATE QUESTION SENTINEL', $raw);
        $this->assertStringContainsString('PRIVATE QUESTION SENTINEL', Crypt::decryptString($raw));
        $this->assertStringNotContainsString('PRIVATE OWNER SENTINEL', json_encode($preview));
        foreach (DB::table('team_operation_revisions')->where('record_id', $preview['id'])->get() as $row) {
            $audit = Crypt::decryptString($row->payload);
            $this->assertStringNotContainsString('PRIVATE QUESTION SENTINEL', $audit);
            $this->assertStringNotContainsString('Fictional job', $audit);
            $this->assertStringNotContainsString('sk-FICTIONAL', $audit);
            $this->assertNull($row->user_id);
        }
        $this->denied(fn () => $receipts->prepare($scope, 'Changed question', $this->snapshot(), $key), 409);
        $empty = $this->snapshot();
        $empty['sources'] = [];
        $this->denied(fn () => $receipts->prepare($scope, 'No evidence', $empty, (string) Str::uuid()), 422);
        for ($i = 0; $i < 9; $i++) {
            $receipts->prepare($scope, 'Fictional question '.$i, $this->snapshot(), (string) Str::uuid());
        }
        $this->denied(fn () => $receipts->prepare($scope, 'Eleventh', $this->snapshot(), (string) Str::uuid()), 429);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }

    public function test_current_browser_edition_digest_and_original_request_are_required_and_prepared_status_is_honestly_unsubmitted(): void
    {
        $scope = $this->configuredConnection();
        $receipts = app(ReviewerAiReceipts::class);
        $preview = $receipts->prepare($scope, 'Fictional question', $this->snapshot(), (string) Str::uuid());
        $receipt = $this->receipt($scope, $preview);
        $this->assertSame('prepared', $receipts->show($scope, $receipt)['status']);
        $other = $scope;
        $other['browser_hash'] = str_repeat('e', 64);
        $this->denied(fn () => $receipts->show($other, $receipt), 404);
        foreach (['digest' => str_repeat('e', 64), 'snapshot_digest' => str_repeat('e', 64), 'request_id' => (string) Str::uuid()] as $key => $value) {
            $this->denied(fn () => $receipts->show($scope, array_replace($receipt, [$key => $value])), 409);
        }
        $this->denied(fn () => $receipts->show($scope, $receipt + ['question' => 'Cannot replace stored fields']), 422);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $receipts->show($scope, $receipt);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->travel(121)->seconds();
        $this->denied(fn () => $receipts->claim($scope, $receipt), 410);
        $this->assertSame('prepared', $receipts->show($scope, $receipt)['status']);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_once_only_receipt_claim_charge_join_and_settled_answer_cannot_redispatch_or_borrow_another_charge(): void
    {
        $scope = $this->configuredConnection();
        $receipts = app(ReviewerAiReceipts::class);
        $budget = app(ReviewerAiBudget::class);
        $preview = $receipts->prepare($scope, 'Show the fictional job', $this->snapshot(), (string) Str::uuid());
        $receipt = $this->receipt($scope, $preview);
        $receipts->claim($scope, $receipt);
        $this->denied(fn () => $receipts->claim($scope, $receipt), 409);
        $other = $receipts->prepare($scope, 'Other question', $this->snapshot(), (string) Str::uuid());
        $this->denied(fn () => $receipts->claim($scope, $this->receipt($scope, $other)), 409);
        $foreign = $budget->reserve('ai', 'wrong-digest', str_repeat('f', 64), $preview['maximum_microusd']);
        $this->denied(fn () => $receipts->attachCharge($scope, $receipt, $foreign['id']), 409);
        $charge = $budget->reserve('ai', 'exact-reviewer-receipt', $preview['digest'], $preview['maximum_microusd']);
        $receipts->attachCharge($scope, $receipt, $charge['id']);
        $this->denied(fn () => $receipts->markDispatched($scope, $receipt), 409);
        $budget->claimDispatch($charge['id'], $preview['digest']);
        $receipts->markDispatched($scope, $receipt);
        $this->denied(fn () => $receipts->markDispatched($scope, $receipt), 409);
        $answer = ['answer' => 'The shared fictional snapshot contains one project.', 'refs' => [$this->snapshot()['sources'][0]['ref']]];
        $this->denied(fn () => $receipts->finish($scope, $receipt, 'accepted', $answer), 409);
        $budget->settle($charge['id'], 'accepted', ['provider_id' => 'resp_SYNTHETIC_ONLY'], 1234);
        $result = $receipts->finish($scope, $receipt, 'accepted', $answer);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame($answer['answer'], $result['answer']);
        $this->assertTrue($result['provider_simulated']);
        $this->assertSame($result, $receipts->show($scope, $receipt));
        $this->denied(fn () => $receipts->claim($scope, $receipt), 409);
        $this->assertSame(2, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(1234, TeamOperationRecord::query()->findOrFail($charge['id'])->data['charged_microusd']);
    }

    public function test_expiry_withholds_content_and_explicit_bounded_scrub_preserves_tombstones_pending_cost_and_all_unrelated_records(): void
    {
        $scope = $this->configuredConnection();
        $receipts = app(ReviewerAiReceipts::class);
        $preview = $receipts->prepare($scope, 'PRIVATE QUESTION TO EXPIRE', $this->snapshot(), (string) Str::uuid());
        $receipt = $this->receipt($scope, $preview);
        $receipts->claim($scope, $receipt);
        $charge = app(ReviewerAiBudget::class)->reserve('ai', 'held-during-expiry', $preview['digest'], $preview['maximum_microusd']);
        $receipts->attachCharge($scope, $receipt, $charge['id']);
        $rawCharge = DB::table('team_operation_records')->where('id', $charge['id'])->first();
        $rawLedger = DB::table('team_operation_records')->where('id', $this->ledger->id)->first();
        $this->travel(601)->seconds();
        $expired = $receipts->show($scope, $receipt);
        $this->assertTrue($expired['content_expired']);
        $this->assertSame('reserved', $expired['status']);
        $this->assertSame($receipt['digest'], $expired['digest']);
        $this->assertArrayNotHasKey('preview', $expired);
        $this->assertSame(['scrubbed' => 1, 'limit' => 1], $receipts->prune(1));
        $row = TeamOperationRecord::query()->findOrFail($preview['id']);
        foreach (['question', 'snapshot', 'result'] as $key) {
            $this->assertArrayNotHasKey($key, $row->data);
        }
        $this->assertSame($preview['digest'], $row->data['digest']);
        $this->assertSame($charge['id'], $row->data['charge_id']);
        $this->assertSame('reserved', $row->status);
        $this->assertNull($row->voice_expires_at);
        $this->assertSame(['scrubbed' => 0, 'limit' => 100], $receipts->prune());
        $this->assertEquals($rawCharge, DB::table('team_operation_records')->where('id', $charge['id'])->first());
        $this->assertEquals($rawLedger, DB::table('team_operation_records')->where('id', $this->ledger->id)->first());
        $this->denied(fn () => $receipts->claim($scope, $receipt), 409);
        $other = $receipts->prepare($scope, 'New preview cannot clear old hold', $this->snapshot(), (string) Str::uuid());
        $this->denied(fn () => $receipts->claim($scope, $this->receipt($scope, $other)), 409);
    }

    public function test_missing_ledger_never_updates_receipt_during_read_claim_or_expiry_cleanup(): void
    {
        $scope = $this->configuredConnection();
        $receipts = app(ReviewerAiReceipts::class);
        $preview = $receipts->prepare($scope, 'Private fictional question', $this->snapshot(), (string) Str::uuid());
        $receipt = $this->receipt($scope, $preview);
        $this->ledger->delete();
        $before = DB::table('team_operation_records')->where('id', $preview['id'])->first();
        foreach ([fn () => $receipts->show($scope, $receipt), fn () => $receipts->claim($scope, $receipt), fn () => $receipts->prune()] as $call) {
            $this->denied($call, 503);
        }
        $this->assertEquals($before, DB::table('team_operation_records')->where('id', $preview['id'])->first());
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
    }
}
