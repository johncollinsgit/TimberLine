<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Models\User;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

final class ReviewerWorkspaceTest extends ReviewerWorkspaceFixture
{
    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('The unsafe operation must fail closed.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function budget(): array
    {
        return (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first();
    }

    public function test_two_independent_real_unlocked_sessions_share_one_server_graph_with_ai_off_and_no_native_identity_or_budget_writes(): void
    {
        $this->initialize();
        $workspace = app(ReviewerWorkspace::class);
        $one = $this->recipient();
        $two = $this->recipient();
        $before = [$this->budget(), User::count(), DB::table('tenant_user')->get()->toJson(), DB::table('team_area_members')->get()->toJson()];
        $first = $workspace->projection($one, $this->token, $this->binding(), $this->selection());
        $this->assertSame(1, $first['revision']);
        $this->assertCount(2, $first['allowed_areas']);
        $command = $this->command();
        $result = $workspace->command($one, $this->token, $command);
        $this->assertSame(2, $result['receipt']['revision']);
        $seen = $workspace->projection($two, $this->token, $this->binding(), $this->selection());
        $this->assertSame('Edited fictional roof check', $seen['graph']['projects'][0]['title']);
        $this->assertSame('server_shared_fictional', $seen['origin']);
        $this->assertSame(2, $seen['revision']);
        $this->assertNull($two->user());
        $this->assertNotSame($one->session()->getId(), $two->session()->getId());
        $this->assertStringNotContainsString('owner_capability_hash', json_encode($seen));
        $this->assertStringNotContainsString('PRIVATE OWNER SENTINEL', json_encode($seen));
        $this->assertSame($before, [$this->budget(), User::count(), DB::table('tenant_user')->get()->toJson(), DB::table('team_area_members')->get()->toJson()]);
        $this->assertFalse(config('service_proposals.reviewer_ai.enabled'));
        $this->assertFalse(config('enterprise.trial.enabled'));
    }

    public function test_explicit_owner_initialization_is_exact_once_and_recipient_reads_never_seed_or_reset_existing_edits(): void
    {
        $workspace = app(ReviewerWorkspace::class);
        $reader = $this->recipient();
        $this->denied(fn () => $workspace->projection($reader, $this->token, $this->binding(), $this->selection()), 404);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
        $this->assertFalse($this->initialize()['replayed']);
        $workspace->command($reader, $this->token, $this->command());
        $this->assertTrue($this->initialize()['replayed']);
        $this->assertSame(2, $workspace->projection($reader, $this->token, $this->binding(), $this->selection())['revision']);
        $intent = ['request_id' => (string) Str::uuid(), 'descriptor_sha256' => ReviewerWorkspaceSchema::digest($this->version->payload['review_workspace'])];
        $anonymousOwnerRoute = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $anonymousOwnerRoute->setUserResolver(fn () => null);
        $this->denied(fn () => $workspace->initialize($anonymousOwnerRoute, $this->version->id, $this->seed, $intent), 403);
        $owner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $this->denied(fn () => $workspace->initialize($owner, $this->version->id, $this->seed, $intent), 409);
        config(['service_proposals.reviewer_workspace.initialization_enabled' => false]);
        $this->denied(fn () => $this->initialize(), 403);
    }

    public function test_revision_conflict_exact_replay_and_status_never_duplicate_writes_and_not_observed_is_not_settled(): void
    {
        $this->initialize();
        $workspace = app(ReviewerWorkspace::class);
        $one = $this->recipient();
        $two = $this->recipient();
        $command = $this->command();
        $notSeen = $workspace->commandStatus($one, $this->token, $this->binding(), $this->selection(), $command['request_id'], ReviewerWorkspaceSchema::commandDigest($command));
        $this->assertSame(['status' => 'not_observed', 'settled' => false], $notSeen);
        $workspace->command($one, $this->token, $command);
        $this->denied(fn () => $workspace->command($two, $this->token, $this->command()), 409);
        $this->assertTrue($workspace->command($one, $this->token, $command)['replayed']);
        $changed = $command;
        $changed['payload']['title'] = 'Different same request';
        $this->denied(fn () => $workspace->command($one, $this->token, $changed), 409);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::COMMAND)->count());
        $status = $workspace->commandStatus($one, $this->token, $this->binding(), $this->selection(), $command['request_id'], ReviewerWorkspaceSchema::commandDigest($command));
        $this->assertTrue($status['settled']);
        $this->assertSame(2, $status['projection']['revision']);
        $this->assertSame(['status' => 'not_observed', 'settled' => false], $workspace->commandStatus($two, $this->token, $this->binding(), $this->selection(), $command['request_id'], ReviewerWorkspaceSchema::commandDigest($command)));
    }

    public function test_server_facts_ignore_browser_authority_and_full_revision_invalidates_even_an_unselected_department_change(): void
    {
        $this->initialize();
        $workspace = app(ReviewerWorkspace::class);
        $one = $this->recipient();
        $facts = $workspace->facts($one, $this->token, $this->binding(), $this->selection());
        $this->assertSame(2, $facts['snapshot']['schema_version']);
        $groups = $facts['snapshot']['sources'][0]['comparisons'];
        $this->assertNull($groups[0]['estimate_minor']);
        $this->assertSame(0, $groups[1]['estimate_minor']);
        $this->assertSame(250, $groups[1]['difference_minor']);
        $this->assertSame(['matches' => true], $workspace->statusFacts($one, $this->token, $this->binding(), $this->selection(), $facts['pin']));
        $command = $this->command(['selection' => ['persona' => 'demo_manager', 'area_ids' => [$this->ids['b']], 'person_id' => null], 'record_id' => $this->ids['other_project'],
            'payload' => ['title' => 'Changed unselected fictional IT', 'description' => '', 'owner_id' => 'p2', 'due_on' => null]]);
        $workspace->command($one, $this->token, $command);
        $this->assertSame(['matches' => false], $workspace->statusFacts($one, $this->token, $this->binding(), $this->selection(), $facts['pin']));
        $this->denied(fn () => $workspace->assertFacts($one, $this->token, $this->binding(), $this->selection(), $facts['pin']), 409);
        $this->version->revoked_at = now();
        $this->version->save();
        $this->denied(fn () => $workspace->statusFacts($one, $this->token, $this->binding(), $this->selection(), $facts['pin']), 410);
    }

    public function test_worker_and_requester_are_narrowed_and_requester_ownership_comes_only_from_current_session_nonce(): void
    {
        $this->initialize();
        $workspace = app(ReviewerWorkspace::class);
        $one = $this->recipient();
        $two = $this->recipient();
        $worker = $workspace->projection($one, $this->token, $this->binding(), $this->selection('worker', 'p1'));
        $this->assertCount(1, $worker['graph']['tasks']);
        $this->assertSame([], $worker['graph']['costs']);
        $this->assertSame(['task.status'], $worker['graph']['tasks'][0]['allowed_actions']);
        $this->denied(fn () => $workspace->command($one, $this->token, $this->command(['selection' => $this->selection('worker', 'p1')])), 403);
        $requester = $this->selection('requester');
        $this->assertSame([], $workspace->projection($one, $this->token, $this->binding(), $requester)['graph']['tickets']);
        $id = (string) Str::uuid();
        $body = $this->command(['selection' => $requester, 'action' => 'ticket.create', 'record_id' => $id, 'expected_record_version' => 0,
            'payload' => ['area_id' => $this->ids['a'], 'title' => 'Own fictional request', 'description' => '', 'priority' => null, 'due_on' => null,
                'building_id' => $this->ids['building'], 'building_version' => 1, 'type' => 'general']]);
        $workspace->command($one, $this->token, $body);
        $this->assertCount(1, $workspace->projection($one, $this->token, $this->binding(), $requester)['graph']['tickets']);
        $this->assertCount(0, $workspace->projection($two, $this->token, $this->binding(), $requester)['graph']['tickets']);
        $one->session()->put('service_proposal_name.'.$this->version->id, 'Blaine');
        $two->session()->put('service_proposal_name.'.$this->version->id, 'Blaine');
        $this->assertCount(0, $workspace->projection($two, $this->token, $this->binding(), $requester)['graph']['tickets']);
        $one->session()->regenerate();
        $this->assertCount(1, $workspace->projection($one, $this->token, $this->binding(), $requester)['graph']['tickets']);
    }
}
