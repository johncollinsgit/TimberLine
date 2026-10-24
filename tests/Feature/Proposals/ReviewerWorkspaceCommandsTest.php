<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceController;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

final class ReviewerWorkspaceCommandsTest extends ReviewerWorkspaceFixture
{
    private function denied(callable $operation, int $expected): void
    {
        try {
            $operation();
            $this->fail('Must refuse.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($expected, $error->getStatusCode());
        }
    }

    private function rows(): string
    {
        return DB::table('team_operation_records')->orderBy('id')->get()->toJson();
    }

    private function http(Request $context, string $body): Request
    {
        $request = Request::create('https://proposal.example.invalid/enterprise-owner/fixture', 'POST', [], [], [], [
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php',
            'HTTPS' => 'on', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_CSRF_TOKEN' => $context->session()->token()], $body);
        $request->setLaravelSession($context->session());
        $request->setUserResolver(fn () => null);

        return $request;
    }

    public function test_job_wizard_creates_exact_three_joined_records_atomically_and_bad_fk_preserves_every_record(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $project = (string) Str::uuid();
        $task = (string) Str::uuid();
        $ticket = (string) Str::uuid();
        $command = $this->command(['action' => 'job.create', 'record_id' => $project, 'expected_record_version' => 0,
            'payload' => ['area_id' => $this->ids['a'], 'title' => 'Fictional new grounds job', 'description' => 'Reviewed fictional wizard entry',
                'task_title' => 'Fictional repair steps', 'owner_id' => 'p1', 'task_id' => $task, 'ticket_id' => $ticket, 'assignee_id' => 'p2',
                'building_id' => $this->ids['building'], 'building_version' => 1, 'priority' => 'high', 'due_on' => null, 'type' => 'maintenance']]);
        $before = $this->rows();
        $this->denied(fn () => $service->command($context, $this->token, $command), 422);
        $this->assertSame($before, $this->rows());
        $command['payload']['assignee_id'] = 'p1';
        $result = $service->command($context, $this->token, $command);
        $graph = $result['projection']['graph'];
        $this->assertSame(2, count($graph['projects']));
        $this->assertSame(2, count($graph['tasks']));
        $this->assertSame(3, count($graph['tickets']));
        $newTask = collect($graph['tasks'])->firstWhere('id', $task);
        $newTicket = collect($graph['tickets'])->firstWhere('id', $ticket);
        $this->assertSame($project, $newTask['project_id']);
        $this->assertSame($task, $newTicket['task_id']);
        $this->assertSame($this->ids['building'], $newTask['building_id']);
        $this->assertTrue($newTicket['owned_by_caller']);
        $this->assertNotNull($newTask['updated_at']);
        $this->assertSame(ReviewerWorkspaceSchema::commandDigest($command), $result['receipt']['digest']);
        $this->assertTrue($service->command($context, $this->token, $command)['replayed']);
    }

    public function test_move_and_assign_is_atomic_cross_department_with_current_project_and_saved_building_versions(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $command = $this->command(['action' => 'ticket.plan', 'record_id' => $this->ids['incoming'], 'payload' => ['target_area_id' => $this->ids['b'],
            'project_id' => $this->ids['other_project'], 'project_version' => 2, 'task_id' => (string) Str::uuid(), 'assignee_id' => 'p2',
            'building_id' => $this->ids['other_building'], 'building_version' => 1, 'acknowledge_routing' => true]]);
        $before = $this->rows();
        $this->denied(fn () => $service->command($context, $this->token, $command), 409);
        $this->assertSame($before, $this->rows());
        $command['payload']['project_version'] = 1;
        $service->command($context, $this->token, $command);
        $other = ['persona' => 'demo_manager', 'area_ids' => [$this->ids['b']], 'person_id' => null];
        $graph = $service->projection($context, $this->token, $this->binding(), $other)['graph'];
        $this->assertCount(1, $graph['tickets']);
        $this->assertCount(1, $graph['tasks']);
        $this->assertSame('p2', $graph['tasks'][0]['assignee_id']);
        $this->assertSame($this->ids['other_building'], $graph['tickets'][0]['building_id']);
        $this->assertSame($graph['tasks'][0]['id'], $graph['tickets'][0]['task_id']);
        $worker = $service->projection($context, $this->token, $this->binding(), ['persona' => 'worker', 'area_ids' => [$this->ids['b']], 'person_id' => 'p2']);
        $this->assertCount(1, $worker['graph']['tasks']);
        $this->assertCount(1, $worker['graph']['tickets']);
    }

    public function test_plan_create_overlap_cancel_and_soft_archive_preserve_fk_and_never_offer_cost_writes(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $id = (string) Str::uuid();
        $command = $this->command(['action' => 'plan.create', 'record_id' => $id, 'expected_record_version' => 0, 'payload' => ['area_id' => $this->ids['a'],
            'project_id' => $this->ids['project'], 'project_version' => 1, 'contractor_id' => $this->ids['contractor'], 'contractor_version' => 1,
            'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04']]);
        $before = $this->rows();
        $this->denied(fn () => $service->command($context, $this->token, $command), 409);
        $this->assertSame($before, $this->rows());
        $command['payload']['starts_on'] = '2026-11-04';
        $service->command($context, $this->token, $command);
        $cancel = $this->command(['action' => 'plan.cancel', 'record_id' => $id, 'expected_revision' => 2, 'payload' => []]);
        $result = $service->command($context, $this->token, $cancel);
        $this->assertSame('cancelled', collect($result['projection']['graph']['plans'])->firstWhere('id', $id)['status']);
        $this->assertSame(['can_view_costs' => true, 'can_manage_plans' => true, 'can_manage_costs' => false], $result['projection']['capabilities']);
        $archive = $this->command(['action' => 'task.archive', 'record_id' => $this->ids['task'], 'expected_revision' => 3, 'payload' => ['acknowledge_removal' => true]]);
        $graph = $service->command($context, $this->token, $archive)['projection']['graph'];
        $this->assertSame('archived', $graph['tasks'][0]['status']);
        $this->assertSame('closed', collect($graph['tickets'])->firstWhere('id', $this->ids['ticket'])['status']);
        $this->assertSame([], array_values(array_filter($result['projection']['permitted_actions'], fn ($action) => str_starts_with($action, 'cost.'))));
    }

    public function test_http_closed_object_payload_csrf_origin_and_bound_definitive_rejection_never_falsely_settle_an_applied_receipt(): void
    {
        $this->initialize();
        $context = $this->recipient();
        $controller = app(ReviewerWorkspaceController::class);
        $command = $this->command(['action' => 'plan.cancel', 'record_id' => $this->ids['plan'], 'payload' => []]);
        $wire = ReviewerWorkspaceSchema::commandCanonical($command);
        $this->assertStringContainsString('"payload":{}', $wire);
        $bad = $controller->command($this->http($context, str_replace('"payload":{}', '"payload":[]', $wire)), $this->token);
        $this->assertSame(422, $bad->status());
        $this->assertArrayNotHasKey('definitive_not_applied', $bad->getData(true));
        $request = $this->http($context, $wire);
        $request->headers->remove('X-CSRF-TOKEN');
        $this->assertSame(419, $controller->command($request, $this->token)->status());
        $request = $this->http($context, $wire);
        $request->headers->set('Origin', 'https://foreign.example.invalid');
        $this->assertSame(403, $controller->command($request, $this->token)->status());
        $response = $controller->command($this->http($context, $wire), $this->token);
        $this->assertSame(200, $response->status());
        $this->assertSame(ReviewerWorkspaceSchema::commandDigest($command), $response->getData(true)['receipt']['digest']);
        $stale = $this->command();
        $response = $controller->command($this->http($context, ReviewerWorkspaceSchema::commandCanonical($stale)), $this->token);
        $this->assertSame(409, $response->status());
        $this->assertSame(['error' => 'workspace_conflict', 'automatic_retry' => false, 'definitive_not_applied' => true,
            'request_id' => $stale['request_id'], 'digest' => ReviewerWorkspaceSchema::commandDigest($stale), 'workspace_id' => $this->version->payload['review_workspace']['workspace_id']], $response->getData(true));
        $command['payload'] = ['unexpected' => 'different already-applied body'];
        $response = $controller->command($this->http($context, ReviewerWorkspaceSchema::commandCanonical($command)), $this->token);
        $this->assertSame(409, $response->status());
        $this->assertArrayNotHasKey('definitive_not_applied', $response->getData(true));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_unknown_payload_financial_off_worker_and_missing_descriptor_do_not_grant_or_seed(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $command = $this->command();
        $command['payload']['sql'] = 'not an operation';
        $before = $this->rows();
        $this->denied(fn () => $service->command($context, $this->token, $command), 422);
        $this->assertSame($before, $this->rows());
        $facts = $service->facts($context, $this->token, $this->binding(), $this->selection());
        config(['service_proposals.reviewer_workspace.financial_enabled' => false]);
        $projection = $service->projection($context, $this->token, $this->binding(), $this->selection());
        $this->assertFalse($projection['capabilities']['can_view_costs']);
        $this->assertSame([], $projection['graph']['costs']);
        $this->assertSame(['matches' => false], $service->statusFacts($context, $this->token, $this->binding(), $this->selection(), $facts['pin']));
        config(['service_proposals.reviewer_workspace.writes_enabled' => false]);
        $this->assertSame([], $service->projection($context, $this->token, $this->binding(), $this->selection())['permitted_actions']);
        $this->denied(fn () => $service->command($context, $this->token, $this->command()), 403);
        $this->publish(false);
        $other = $this->recipient();
        $this->denied(fn () => $service->projection($other, $this->token, $this->binding(), $this->selection()), 404);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
        config(['service_proposals.reviewer_workspace.enabled' => false]);
        $this->denied(fn () => $service->projection($other, $this->token, $this->binding(), $this->selection()), 404);
    }

    public function test_expired_or_revoked_graph_erasure_is_owner_only_bounded_idempotent_and_works_after_flags_off_without_budget_mutation(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $service->command($context, $this->token, $this->command());
        $before = (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first();
        $owner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $this->assertSame(0, $service->pruneExpired($owner)['erased']);
        $anonymous = clone $owner;
        $anonymous->setUserResolver(fn () => null);
        $this->denied(fn () => $service->pruneExpired($anonymous), 403);
        $this->version->revoked_at = now();
        $this->version->save();
        config(['service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.reviewer_workspace.writes_enabled' => false]);
        $this->assertSame(['processed' => 1, 'erased' => 1, 'next_cursor' => null], $service->pruneExpired($owner, 1));
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->first();
        $this->assertSame('expired', $row->status);
        $this->assertNull($row->data['graph']);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::COMMAND)->count());
        $this->assertSame(0, $service->pruneExpired($owner)['erased']);
        $this->assertSame($before, (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first());
        $this->denied(fn () => $service->pruneExpired($owner, 101), 422);
    }

    public function test_editor_commands_write_only_synthetic_records_revisions_and_the_existing_tenant_lock(): void
    {
        $this->initialize();
        $context = $this->recipient();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(?:insert(?: or ignore)? into|update|delete from) ["`]?([a-z_]+)/i', $query->sql, $match)) {
                $writes[] = $match[1];
            }
        });
        app(ReviewerWorkspace::class)->command($context, $this->token, $this->command());
        $this->assertNotEmpty($writes);
        $this->assertSame([], array_values(array_diff(array_unique($writes), ['team_work_locks', 'team_operation_records', 'team_operation_revisions'])));
    }

    public function test_current_policy_and_capability_revocation_cross_edition_binding_and_synthetic_anchor_drift_refuse(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $oldBinding = $this->binding();
        $facts = $service->facts($context, $this->token, $oldBinding, $this->selection());
        $key = 'service_proposals.reviewer_workspace.policies.'.ReviewerWorkspaceSchema::digest($this->policy);
        config([$key => []]);
        $this->denied(fn () => $service->facts($context, $this->token, $oldBinding, $this->selection()), 422);
        config([$key => $this->policy]);
        $context->session()->forget('service_proposal_access.'.$this->version->id);
        $this->denied(fn () => $service->statusFacts($context, $this->token, $oldBinding, $this->selection(), $facts['pin']), 403);
        $this->publish();
        $new = $this->recipient();
        $this->denied(fn () => $service->projection($new, $this->token, $oldBinding, $this->selection()), 409);
        $this->denied(fn () => $service->projection($new, $this->token, $this->binding(), $this->selection()), 404);
        $this->initialize();
        config(['service_proposals.reviewer_workspace.anchor_marker_sha256' => str_repeat('f', 64)]);
        $this->denied(fn () => $service->facts($new, $this->token, $this->binding(), $this->selection()), 503);
    }

    public function test_immutable_viewer_policy_is_readonly_even_for_requester_and_cannot_read_financial_without_both_gates(): void
    {
        $schema = app(ReviewerWorkspaceSchema::class);
        $this->policy = $schema->policy(array_replace($this->policy, ['max_role' => 'viewer', 'allow_financial' => false]));
        config(['service_proposals.reviewer_workspace.policies' => [ReviewerWorkspaceSchema::digest($this->policy) => $this->policy]]);
        $this->publish();
        $this->initialize();
        $context = $this->recipient();
        $service = app(ReviewerWorkspace::class);
        $projection = $service->projection($context, $this->token, $this->binding(), $this->selection());
        $this->assertSame([], $projection['permitted_actions']);
        $this->assertFalse($projection['capabilities']['can_view_costs']);
        $this->assertSame([], $projection['graph']['costs']);
        $requester = $service->projection($context, $this->token, $this->binding(), $this->selection('requester'));
        $this->assertSame([], $requester['permitted_actions']);
        $this->denied(fn () => $service->command($context, $this->token, $this->command()), 403);
        $this->denied(fn () => $service->facts($context, $this->token, $this->binding(), $this->selection('worker', 'p2')), 403);
    }

    public function test_workspace_and_ai_bounds_fail_explicitly_without_silent_truncation_and_command_cap_rolls_back(): void
    {
        $schema = app(ReviewerWorkspaceSchema::class);
        $graph = $this->seed;
        $graph['projects'][0]['description'] = str_repeat('x', 5001);
        $this->denied(fn () => $schema->graph($graph), 422);
        $graph = $this->seed;
        for ($i = 2; $i <= 50; $i++) {
            $graph['people'][] = ['id' => 'extra'.$i, 'name' => 'Fictional extra', 'version' => 1, 'area_ids' => [$this->ids['a']], 'active' => true, 'updated_at' => null];
        }
        $this->denied(fn () => $schema->graph($graph), 422);
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $context = $this->recipient();
        $service->command($context, $this->token, $this->command());
        $receipt = (array) DB::table('team_operation_records')->where('kind', ReviewerWorkspaceStore::COMMAND)->first();
        $copies = [];
        for ($i = 1; $i < ReviewerWorkspaceStore::COMMAND_CAP; $i++) {
            $copies[] = array_replace($receipt, ['id' => (string) Str::uuid(), 'source_key' => hash('sha256', 'fictional-cap-'.$i)]);
        }
        foreach (array_chunk($copies, 100) as $chunk) {
            DB::table('team_operation_records')->insert($chunk);
        }
        $before = $this->rows();
        $this->denied(fn () => $service->command($context, $this->token, $this->command(['expected_revision' => 2, 'expected_record_version' => 2])), 429);
        $this->assertSame($before, $this->rows());
    }

    public function test_full_ui_graph_can_exceed_ai_selection_bound_but_ai_never_silently_truncates_sources(): void
    {
        $graph = $this->seed;
        for ($i = 1; $i <= 32; $i++) {
            $graph['projects'][] = array_replace($graph['projects'][0], ['id' => (string) Str::uuid(), 'title' => 'Fictional additional project '.$i]);
        }
        $this->seed = app(ReviewerWorkspaceSchema::class)->graph($graph, true);
        $this->publish();
        $this->initialize();
        $context = $this->recipient();
        $service = app(ReviewerWorkspace::class);
        $this->assertCount(33, $service->projection($context, $this->token, $this->binding(), $this->selection())['graph']['projects']);
        $before = $this->rows();
        $this->denied(fn () => $service->facts($context, $this->token, $this->binding(), $this->selection()), 422);
        $this->assertSame($before, $this->rows());
    }

    public function test_explicit_facts_http_read_returns_only_current_server_evidence_pin_with_no_graph_or_writes(): void
    {
        $this->initialize();
        $context = $this->recipient();
        $before = $this->rows();
        $controller = app(ReviewerWorkspaceController::class);
        $body = json_encode(['binding' => $this->binding(), 'selection' => $this->selection()], JSON_THROW_ON_ERROR);
        $response = $controller->facts($this->http($context, $body), $this->token);
        $this->assertSame(200, $response->status());
        $value = $response->getData(true);
        $keys = array_keys($value);
        sort($keys);
        $this->assertSame(['pin', 'snapshot'], $keys);
        $this->assertSame(app(ReviewerWorkspace::class)->facts($context, $this->token, $this->binding(), $this->selection()), $value);
        $this->assertStringNotContainsString('owner_capability_hash', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE OWNER SENTINEL', $response->getContent());
        $this->assertSame($before, $this->rows());
        $request = $this->http($context, $body);
        $request->headers->remove('X-CSRF-TOKEN');
        $this->assertSame(419, $controller->facts($request, $this->token)->status());
    }
}
