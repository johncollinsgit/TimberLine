<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiAccess;
use App\Proposals\ReviewerAiConnection;
use App\Proposals\ReviewerAiOrchestrator;
use App\Proposals\ReviewerAiResponses;
use App\Proposals\ReviewerAiSnapshot;
use App\Proposals\ReviewerAiWorkspace;
use App\Proposals\ReviewerWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerWorkspaceFixture;

/** Actual isolated encrypted shared work and recipient grants; only provider HTTP is intercepted. */
final class ReviewerAiSharedWorkspaceIntegrationTest extends ReviewerWorkspaceFixture
{
    protected function tearDown(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        parent::tearDown();
    }

    private function connection(): void
    {
        $config = config('service_proposals.reviewer_ai') + ['schema_version' => 1, 'approval_id' => (string) Str::uuid(),
            'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'), 'mount' => '/enterprise-owner',
            'project_id' => 'proj_FICTIONAL_SHARED_TEST_ONLY', 'organization_id' => null, 'model' => ReviewerAiConnection::MODEL, 'model_context_tokens' => 400000,
            'max_output_tokens' => 800, 'input_ceiling_microusd_per_million' => 750000, 'output_ceiling_microusd_per_million' => 4500000,
            'per_call_overhead_microusd' => 0, 'model_context_evidence' => 'FICTIONAL TEST model context evidence', 'rates_evidence' => 'FICTIONAL TEST inclusive account rate evidence',
            'version_id' => $this->version->id, 'version_hash' => $this->version->content_hash,
            'build_id' => $this->binding()['build_id'], 'source_hash' => $this->binding()['source_hash'],
            'valid_until' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z'), 'credential_valid_until' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z'),
            'api_key' => 'sk-FICTIONAL-NEVER-VALID-SHARED-TEST-ONLY'];
        $config['enabled'] = $config['ui_enabled'] = true;
        $config['credential_sha256'] = hash('sha256', $config['api_key']);
        $config['approval_sha256'] = hash('sha256', json_encode(ReviewerAiConnection::approvalFields($config), JSON_THROW_ON_ERROR));
        config(['service_proposals.reviewer_ai' => $config]);
    }

    private function ai(Request $request): array
    {
        return app(ReviewerAiAccess::class)->scope($request, $this->token, $this->binding(), startBrowser: true);
    }

    private function prepared(Request $request, string $question, array $history = []): array
    {
        $scope = $this->ai($request);
        $facts = app(ReviewerWorkspace::class)->facts($request, $this->token, $this->binding(), $this->selection());
        $body = ['binding' => $this->binding(), 'workspace' => ['expected_revision' => $facts['pin']['revision'], 'selection' => $this->selection()]];
        $prepared = app(ReviewerAiWorkspace::class)->prepare($scope, $request, $this->token, $body);
        $context = $prepared['scope']['workspace_context'];
        $authority = fn () => app(ReviewerAiWorkspace::class)->attach($this->ai($request), $request, $this->token, $this->binding(), $context);
        $preview = app(ReviewerAiOrchestrator::class)->preview($prepared['scope'], $question, $prepared['snapshot'], (string) Str::uuid(), $authority, $history)['preview'];
        $receipt = ['preview_id' => $preview['id'], 'request_id' => $preview['request_id'], 'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest'], 'binding' => $this->binding()];

        return [$prepared['scope'], $receipt, $authority, $preview];
    }

    private function createTicket(Request $request, int $revision): void
    {
        app(ReviewerWorkspace::class)->command($request, $this->token, ['binding' => $this->binding(), 'selection' => $this->selection(), 'request_id' => (string) Str::uuid(),
            'expected_revision' => $revision, 'action' => 'ticket.create', 'record_id' => (string) Str::uuid(), 'expected_record_version' => 0,
            'payload' => ['area_id' => $this->ids['a'], 'title' => 'Saved by another independent client', 'description' => 'Fictional shared ticket', 'priority' => 'normal',
                'due_on' => null, 'building_id' => $this->ids['building'], 'building_version' => 1, 'type' => 'maintenance']]);
    }

    public function test_two_real_unlocked_sessions_share_server_work_then_ai_uses_only_current_server_fields_and_one_ledger(): void
    {
        $this->initialize();
        $first = $this->recipient();
        $second = $this->recipient();
        $workspace = app(ReviewerWorkspace::class);
        $a = $workspace->facts($first, $this->token, $this->binding(), $this->selection());
        $b = $workspace->facts($second, $this->token, $this->binding(), $this->selection());
        $this->assertSame($a['snapshot'], $b['snapshot']);
        $this->assertNotSame($a['pin']['capability_hash'], $b['pin']['capability_hash']);
        $this->createTicket($second, 1);
        $current = $workspace->facts($first, $this->token, $this->binding(), $this->selection());
        $this->assertSame(2, $current['pin']['revision']);
        $this->assertContains('Saved by another independent client', array_column($current['snapshot']['sources'], 'title'));
        $this->assertSame(ReviewerAiSnapshot::COVERAGE_SERVER, $current['snapshot']['coverage']);
        $this->connection();
        [$scope, $receipt, $authority, $preview] = $this->prepared($first, 'What work is currently saved?');
        $this->assertSame($current['snapshot'], $preview['snapshot']);
        $this->assertSame($current['pin'], $preview['workspace']['pin']);
        Http::fake(function ($http) use ($current) {
            $payload = json_decode($http->data()['input'][0]['content'], true);
            $this->assertSame($current['snapshot'], $payload['snapshot']);
            $this->assertStringNotContainsString('PRIVATE OWNER SENTINEL', json_encode($payload));
            $this->assertFalse($http->data()['store']);

            return Http::response(['id' => 'resp_FICTIONAL_SHARED_TEST_ONLY', 'model' => ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'total_tokens' => 1050],
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text',
                    'text' => json_encode(['answer' => 'The fictional shared workspace contains the saved ticket.', 'refs' => [$current['snapshot']['sources'][0]['ref']]])]]]]]);
        });
        $answer = app(ReviewerAiOrchestrator::class)->confirm($scope, $receipt, $authority);
        $this->assertSame('accepted', $answer['status']);
        Http::assertSentCount(1);
        $before = DB::table('team_operation_records')->where('kind', 'ent_trial_charge')->count();
        [$followScope, $followReceipt, $followAuthority, $followPreview] = $this->prepared($first, 'And its status?', [$receipt]);
        $this->assertCount(1, $followPreview['history']);
        $this->createTicket($second, 2);
        try {
            app(ReviewerAiOrchestrator::class)->confirm($followScope, $followReceipt, $followAuthority);
            $this->fail('Changed shared evidence must invalidate consent before dispatch.');
        } catch (HttpException $failure) {
            $this->assertSame(409, $failure->getStatusCode());
        }
        Http::assertSentCount(1);
        $this->assertSame($before, DB::table('team_operation_records')->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }
}
