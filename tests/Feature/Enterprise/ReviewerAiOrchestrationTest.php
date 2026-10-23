<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Conversation\Connection;
use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiConnection;
use App\Proposals\ReviewerAiOrchestrator;
use App\Proposals\ReviewerAiReceipts;
use App\Proposals\ReviewerAiRecovery;
use App\Proposals\ReviewerAiResponses;
use App\Proposals\ReviewerAiSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerAiConnectionFixture;

final class ReviewerAiOrchestrationTest extends ReviewerAiConnectionFixture
{
    private function snapshot(): array
    {
        return ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 1,
            'area_ids' => ['73000000-0000-4000-8000-000000000001'], 'sources' => [['ref' => 'project:75000000-0000-4000-8000-000000000001',
                'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'Fictional job', 'version' => 1,
                'status' => 'active', 'tasks' => [], 'buildings' => ['Fictional building']]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
    }

    private function prepare(array $scope): array
    {
        $preview = app(ReviewerAiOrchestrator::class)->preview($scope, 'PRIVATE QUESTION SENTINEL', $this->snapshot(), (string) Str::uuid(), fn () => $scope)['preview'];

        return ['preview_id' => $preview['id'], 'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest'], 'request_id' => $preview['request_id'],
            'binding' => ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']]];
    }

    private function envelope(): array
    {
        return ['id' => 'resp_FICTIONAL_ONLY', 'model' => ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'total_tokens' => 1050],
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'answer' => 'The fictional project is active.', 'refs' => [$this->snapshot()['sources'][0]['ref']]])]]]]];
    }

    private function denied(callable $callback, int $status): void
    {
        try {
            $callback();
            $this->fail('The unsafe or expired boundary must fail closed.');
        } catch (HttpException $failure) {
            $this->assertSame($status, $failure->getStatusCode());
            $this->assertStringNotContainsString('SECRET_PROVIDER_SENTINEL', $failure->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Every fake-provider test checks its own count; the base fixture additionally forbids real network.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        parent::tearDown();
    }

    public function test_exact_dispatch_commits_before_http_and_replay_and_recovery_reads_cannot_dispatch_again(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $settings = app(ReviewerAiConnection::class)->settings($scope);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        Http::fake(function () use ($receipt) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('dispatching', TeamOperationRecord::query()->findOrFail($receipt['preview_id'])->status);
            $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole();
            $this->assertSame('dispatching', $charge->status);
            $this->assertSame($receipt['digest'], $charge->data['input_hash']);
            $this->assertNotNull($charge->data['dispatch_claimed_at']);

            return Http::response($this->envelope());
        });
        $service = app(ReviewerAiOrchestrator::class);
        $result = $service->confirm($scope, $receipt, fn () => $scope);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame($receipt['digest'], $result['digest']);
        $this->assertTrue($result['provider_simulated']);
        $this->assertSame('The fictional project is active.', $result['answer']);
        $this->assertSame($result, $service->confirm($scope, $receipt, fn () => $scope));
        $before = DB::table('team_operation_records')->get()->toJson();
        $this->assertSame($result, $service->status($scope, $receipt, fn () => $scope));
        $this->assertSame($before, DB::table('team_operation_records')->get()->toJson());
        Http::assertSentCount(1);
        $this->assertSame(Connection::ceilingCost(1000, 50, $settings), TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole()->data['charged_microusd']);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }

    public function test_timeout_holds_full_maximum_pauses_campaign_and_no_retry_or_next_preview_can_dispatch(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('SECRET_PROVIDER_SENTINEL');
        });
        $service = app(ReviewerAiOrchestrator::class);
        $this->denied(fn () => $service->confirm($scope, $receipt, fn () => $scope), 503);
        $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole();
        $this->assertSame('uncertain', $charge->status);
        $this->assertSame($charge->data['reserved_microusd'], $charge->data['charged_microusd']);
        $this->assertTrue($this->ledger->fresh()->data['paused']);
        $this->assertSame('uncertain', $service->confirm($scope, $receipt, fn () => $scope)['status']);
        $next = $this->prepare($scope);
        $this->denied(fn () => $service->confirm($scope, $next, fn () => $scope), 409);
        $this->assertSame(1, $calls);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_revocation_after_io_withholds_answer_and_deactivation_still_records_full_hold_and_pause(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        Http::fake(function () {
            config(['service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false]);

            return Http::response($this->envelope());
        });
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, fn () => $scope), 503);
        Http::assertSentCount(1);
        $row = TeamOperationRecord::query()->findOrFail($receipt['preview_id']);
        $this->assertSame('uncertain', $row->status);
        $this->assertArrayNotHasKey('result', $row->data);
        $this->assertTrue($this->ledger->fresh()->data['paused']);
        $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole();
        $this->assertSame($charge->data['reserved_microusd'], $charge->data['charged_microusd']);
    }

    public static function boundaries(): array
    {
        return array_map(fn ($boundary) => [$boundary], range(1, 10));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function test_every_admission_boundary_rechecks_and_authority_loss_never_releases_a_result(int $boundary): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $checks = 0;
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response($this->envelope());
        });
        $authority = function () use ($scope, &$checks, $boundary) {
            $checks++;
            abort_if($checks === $boundary, 403);

            return $scope;
        };
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, $authority), $boundary < 3 ? 403 : 503);
        $this->assertSame($boundary >= 8 ? 1 : 0, $calls);
        $this->assertSame($boundary, $checks);
        $this->assertSame($boundary >= 3, $this->ledger->fresh()->data['paused'] ?? false);
        $this->assertArrayNotHasKey('result', TeamOperationRecord::query()->findOrFail($receipt['preview_id'])->data);
        if ($boundary >= 4) {
            $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole();
            $this->assertSame($boundary >= 9 ? 'accepted' : 'uncertain', $charge->status);
        }
    }

    public function test_changed_browser_after_io_never_release_a_result_or_refund(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $current = $scope;
        Http::fake(function () use (&$current) {
            $current['browser_hash'] = str_repeat('f', 64);

            return Http::response($this->envelope());
        });
        $authority = function () use (&$current) {
            return $current;
        };
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, $authority), 503);
        $this->assertTrue($this->ledger->fresh()->data['paused']);
        $this->assertArrayNotHasKey('result', TeamOperationRecord::query()->findOrFail($receipt['preview_id'])->data);
        Http::assertSentCount(1);
    }

    public function test_malformed_provider_response_is_sanitized_held_and_paused_without_retry(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $envelope = $this->envelope();
        $envelope['usage']['total_tokens'] = 9000000;
        $envelope['error'] = 'SECRET_PROVIDER_SENTINEL';
        Http::fake(['api.openai.com/*' => Http::response($envelope)]);
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, fn () => $scope), 503);
        $this->assertTrue($this->ledger->fresh()->data['paused']);
        $this->assertSame('uncertain', TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole()->status);
        Http::assertSentCount(1);
    }

    public function test_failed_recovery_pin_validation_retains_original_full_dispatch_reservation_and_never_reinitializes(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            config(['enterprise_client.silo_id' => (string) Str::uuid()]);
            throw new ConnectionException('SECRET_PROVIDER_SENTINEL');
        });
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, fn () => $scope), 503);
        $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole();
        $this->assertSame('dispatching', $charge->status);
        $this->assertGreaterThan(0, $charge->data['reserved_microusd']);
        $this->assertArrayNotHasKey('charged_microusd', $charge->data);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $this->assertSame(1, $calls);
    }

    public function test_atomic_cancel_blocks_delayed_confirm_and_claim_blocks_cancel_with_no_refund(): void
    {
        $scope = $this->configuredConnection();
        $receipt = $this->prepare($scope);
        $service = app(ReviewerAiOrchestrator::class);
        $cancelled = $service->cancel($scope, $receipt, fn () => $scope);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertTrue($cancelled['content_expired']);
        $this->assertArrayNotHasKey('preview', $cancelled);
        $this->assertSame($cancelled, $service->cancel($scope, $receipt, fn () => $scope));
        $this->assertSame($cancelled, $service->confirm($scope, $receipt, fn () => $scope));
        $next = $this->prepare($scope);
        app(ReviewerAiReceipts::class)->claim($scope, $next);
        $this->denied(fn () => $service->cancel($scope, $next, fn () => $scope), 409);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        Http::assertNothingSent();
    }

    public function test_expired_status_is_content_free_and_bounded_deactivation_erasure_retains_every_cost_row(): void
    {
        $scope = $this->configuredConnection();
        $first = $this->prepare($scope);
        $second = $this->prepare($scope);
        $recovery = app(ReviewerAiRecovery::class);
        $pins = $recovery->pins(app(ReviewerAiConnection::class)->settings($scope));
        $ledgerBefore = DB::table('team_operation_records')->where('id', $this->ledger->id)->first();
        $this->denied(fn () => $recovery->scrub($pins, 1, true), 409);
        $this->travel(601)->seconds();
        $status = app(ReviewerAiOrchestrator::class)->status($scope, $first, fn () => $scope);
        $this->assertSame('prepared', $status['status']);
        $this->assertTrue($status['content_expired']);
        $this->assertArrayNotHasKey('preview', $status);
        $this->assertStringNotContainsString('PRIVATE QUESTION SENTINEL', json_encode($status));
        $this->assertSame(['scrubbed' => 1, 'limit' => 1, 'deactivated' => false], $recovery->scrub($pins, 1));
        config(['service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false]);
        $this->assertSame(['scrubbed' => 1, 'limit' => 100, 'deactivated' => true], $recovery->scrub($pins, 100, true));
        $this->assertEquals($ledgerBefore, DB::table('team_operation_records')->where('id', $this->ledger->id)->first());
        foreach ([$first, $second] as $receipt) {
            $data = TeamOperationRecord::query()->findOrFail($receipt['preview_id'])->data;
            foreach (['question', 'snapshot', 'result'] as $key) {
                $this->assertArrayNotHasKey($key, $data);
            }
            $this->assertSame($receipt['digest'], $data['digest']);
        }
    }
}
