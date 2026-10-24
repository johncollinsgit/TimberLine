<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiOrchestrator;
use App\Proposals\ReviewerAiResponses;
use App\Proposals\ReviewerAiSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ReviewerAiConnectionFixture;

/** Synthetic seam-boundary proof only, exact reviewed source; no backend integration/provider/native identity claim. */
final class ReviewerAiWorkspaceBoundaryProofTest extends ReviewerAiConnectionFixture
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
                'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'SYNTHETIC OLD PROJECT', 'version' => 7,
                'status' => 'active', 'tasks' => [], 'buildings' => []]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
    }

    private function scope(): array
    {
        $scope = $this->configuredConnection();
        $scope['version']->payload = ['review_workspace' => ['schema_version' => 1, 'workspace_id' => (string) Str::uuid()]];

        return $scope + ['workspace_context' => ['selection' => ['persona' => 'demo_manager', 'area_ids' => $this->snapshot()['area_ids'], 'person_id' => null],
            'pin' => ['revision' => 7, 'workspace_digest' => str_repeat('a', 64)]], 'workspace_stale' => false, 'workspace_recheck' => fn () => null];
    }

    private function receipt(array $scope, array $history = []): array
    {
        $preview = app(ReviewerAiOrchestrator::class)->preview($scope, 'What is current?', $this->snapshot(), (string) Str::uuid(), fn () => $scope, $history)['preview'];

        return ['preview_id' => $preview['id'], 'request_id' => $preview['request_id'], 'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest'],
            'binding' => ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']]];
    }

    private function fakeProvider(callable $onCall): void
    {
        Http::fake(function ($http) use ($onCall) {
            $onCall();

            return Http::response(['id' => 'resp_SYNTHETIC_SEAM_BOUNDARY', 'model' => ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'total_tokens' => 1050],
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text',
                    'text' => json_encode(['answer' => 'SYNTHETIC OLD ANSWER', 'refs' => [$this->snapshot()['sources'][0]['ref']]])]]]]]);
        });
    }

    public function test_expiry_crossed_by_last_workspace_read_must_prevent_http(): void
    {
        $scope = $this->scope();
        $first = $this->receipt($scope);
        $calls = 0;
        $this->fakeProvider(function () use (&$calls) {
            $calls++;
        });
        $this->assertSame('accepted', app(ReviewerAiOrchestrator::class)->confirm($scope, $first, fn () => $scope)['status']);
        $expiry = TeamOperationRecord::query()->findOrFail($first['preview_id'])->data['content_expires_at'];
        $this->travelTo(now()->setTimestamp($expiry - 1));
        $receipt = $this->receipt($scope, [$first]);
        $reads = 0;
        $scope['workspace_recheck'] = function () use ($expiry, &$reads) {
            $reads++;
            $this->travelTo(now()->setTimestamp($expiry + 1));
        };
        $calls = 0;
        try {
            app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, fn () => $scope);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }

        $this->assertSame(0, $calls, 'Content must not reach paid provider after the final workspace read crossed its expiry.');
    }

    public function test_status_final_nonstrict_drift_must_strip_content_already_assembled(): void
    {
        $scope = $this->scope();
        $receipt = $this->receipt($scope);
        $calls = 0;
        $this->fakeProvider(function () use (&$calls) {
            $calls++;
        });
        $this->assertSame('accepted', app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, fn () => $scope)['status']);
        $checks = 0;
        $authority = function () use ($scope, &$checks) {
            // Exactly models statusFacts matching at first read and mismatching at the final read.
            return array_replace($scope, ['workspace_stale' => ++$checks >= 2]);
        };
        $value = app(ReviewerAiOrchestrator::class)->status($scope, $receipt, $authority);

        $this->assertTrue($value['content_expired'], 'The final fresh status scope must fence out old preview/answer content.');
        $this->assertArrayNotHasKey('preview', $value);
        $this->assertArrayNotHasKey('answer', $value);
    }
}
