<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiOrchestrator;
use App\Proposals\ReviewerAiReceipts;
use App\Proposals\ReviewerAiResponses;
use App\Proposals\ReviewerAiSnapshot;
use App\Proposals\ReviewerAiWorkspace;
use App\Proposals\ReviewerWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerAiConnectionFixture;

/** Root seam only: the separate hosted workspace service has its own real-storage authority tests. */
final class ReviewerAiWorkspaceSeamTest extends ReviewerAiConnectionFixture
{
    protected function tearDown(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        parent::tearDown();
    }

    private function snapshot(): array
    {
        return ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 7,
            'area_ids' => ['73000000-0000-4000-8000-000000000001'], 'sources' => [['ref' => 'project:75000000-0000-4000-8000-000000000001',
                'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'Server fictional project', 'version' => 7,
                'status' => 'active', 'tasks' => [], 'buildings' => []]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
    }

    private function denied(callable $callback, int $status): void
    {
        try {
            $callback();
            $this->fail('The shared workspace boundary must refuse stale or browser-supplied facts.');
        } catch (HttpException $failure) {
            $this->assertSame($status, $failure->getStatusCode());
        }
    }

    private function shared(): array
    {
        $scope = $this->configuredConnection();
        $scope['version']->payload = ['review_workspace' => ['schema_version' => 1, 'workspace_id' => (string) Str::uuid()]];
        $binding = ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']];
        $fake = new class($this->snapshot())
        {
            public bool $changed = false;

            public bool $revoked = false;

            public int $reads = 0;

            public function __construct(private array $snapshot) {}

            public function facts(...$args): array
            {
                abort_if($this->revoked, 403);
                $this->reads++;

                return ['snapshot' => $this->snapshot, 'pin' => ['revision' => $this->changed ? 8 : 7, 'workspace_digest' => str_repeat($this->changed ? 'e' : 'a', 64)]];
            }

            public function assertFacts(...$args): array
            {
                $current = $this->facts();
                abort_unless($current['pin'] === $args[4], 409);

                return $current;
            }

            public function statusFacts(...$args): array
            {
                return ['matches' => $this->facts()['pin'] === $args[4]];
            }
        };
        app()->instance(ReviewerWorkspace::class, $fake);
        $request = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/SYNTHETIC/campus/ask');
        $body = ['binding' => $binding, 'workspace' => ['expected_revision' => 7, 'selection' => ['persona' => 'demo_manager', 'area_ids' => $this->snapshot()['area_ids'], 'person_id' => null]]];

        return [$scope, $request, $body, $fake];
    }

    private function preview(array $scope, Request $request, array $body): array
    {
        $workspace = app(ReviewerAiWorkspace::class);
        $prepared = $workspace->prepare($scope, $request, 'SYNTHETIC', $body);
        $context = $prepared['scope']['workspace_context'];
        $authority = fn () => $workspace->attach($scope, $request, 'SYNTHETIC', $body['binding'], $context);
        $preview = app(ReviewerAiOrchestrator::class)->preview($prepared['scope'], 'Which project?', $prepared['snapshot'], (string) Str::uuid(), $authority)['preview'];
        $receipt = ['preview_id' => $preview['id'], 'request_id' => $preview['request_id'], 'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest'], 'binding' => $body['binding']];

        return [$prepared['scope'], $authority, $preview, $receipt];
    }

    public function test_shared_mode_uses_server_facts_and_refuses_browser_snapshot_revision_mismatch_or_legacy_fallback_before_paid_admission(): void
    {
        [$scope, $request, $body, $fake] = $this->shared();
        $workspace = app(ReviewerAiWorkspace::class);
        $this->denied(fn () => $workspace->prepare($scope, $request, 'SYNTHETIC', $body + ['snapshot' => $this->snapshot()]), 422);
        $this->denied(fn () => $workspace->prepare($scope, $request, 'SYNTHETIC', ['binding' => $body['binding'], 'snapshot' => $this->snapshot()]), 422);
        $this->assertSame(0, $fake->reads);
        $wrong = $body;
        $wrong['workspace']['expected_revision'] = 6;
        $this->denied(fn () => $workspace->prepare($scope, $request, 'SYNTHETIC', $wrong), 409);
        [$prepared, $authority, $preview, $receipt] = $this->preview($scope, $request, $body);
        $this->assertSame('Server fictional project', $preview['snapshot']['sources'][0]['title']);
        $this->assertSame(7, $preview['workspace']['pin']['revision']);
        $this->assertSame($preview['workspace'], app(ReviewerAiReceipts::class)->workspaceContext($prepared, $receipt));
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $fake->changed = true;
        $this->denied($authority, 409);
        $this->assertSame(0, DB::table('users')->count());
        Http::assertNothingSent();
    }

    public function test_changed_workspace_before_http_holds_original_charge_without_any_provider_dispatch(): void
    {
        [$scope, $request, $body] = $this->shared();
        [$prepared, , , $receipt] = $this->preview($scope, $request, $body);
        $prepared['workspace_recheck'] = fn () => abort(409); // Models drift during intervening receipt/charge reads after final authority.
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($prepared, $receipt, fn () => $prepared), 503);
        Http::assertNothingSent();
        $this->assertTrue($this->ledger->fresh()->data['paused']);
        $this->assertSame('uncertain', TeamOperationRecord::query()->findOrFail($receipt['preview_id'])->status);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_workspace_drift_after_http_withholds_answer_and_status_can_reconcile_without_reexposing_old_content(): void
    {
        [$scope, $request, $body, $fake] = $this->shared();
        [$prepared, $authority, , $receipt] = $this->preview($scope, $request, $body);
        Http::fake(function ($http) use ($fake) {
            $payload = json_decode($http->data()['input'][0]['content'], true);
            $this->assertArrayNotHasKey('workspace', $payload);
            $this->assertSame('Server fictional project', $payload['snapshot']['sources'][0]['title']);
            $fake->changed = true;

            return Http::response(['id' => 'resp_SYNTHETIC_WORKSPACE', 'model' => ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'total_tokens' => 1050],
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'The fictional project is active.', 'refs' => [$this->snapshot()['sources'][0]['ref']]])]]]]]);
        });
        $this->denied(fn () => app(ReviewerAiOrchestrator::class)->confirm($prepared, $receipt, $authority), 503);
        Http::assertSentCount(1);
        $context = app(ReviewerAiReceipts::class)->workspaceContext($scope, $receipt);
        $read = fn () => app(ReviewerAiWorkspace::class)->attach($scope, $request, 'SYNTHETIC', $body['binding'], $context, false);
        $result = app(ReviewerAiOrchestrator::class)->status($read(), $receipt, $read);
        $this->assertTrue($result['content_expired']);
        $this->assertArrayNotHasKey('preview', $result);
        $this->assertArrayNotHasKey('answer', $result);
        $this->assertSame('uncertain', $result['status']);
        $fake->revoked = true;
        $this->denied($read, 403);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        Http::assertSentCount(1);
    }
}
