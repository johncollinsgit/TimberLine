<?php

namespace Tests\Feature\Proposals;

use App\Http\Middleware\ReviewerAiBoundary;
use App\Models\TeamOperationRecord;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceCollaborationController;
use App\Proposals\ReviewerWorkspaceCollaborationPruner;
use App\Proposals\ReviewerWorkspaceCollaborationStore;
use App\Proposals\ReviewerWorkspaceDiscussion;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

final class ReviewerWorkspaceCollaborationTest extends ReviewerWorkspaceFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        $policy = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false,
            'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $hash = ReviewerWorkspaceSchema::digest($policy);
        config(['service_proposals.reviewer_workspace.collaboration' => ['enabled' => true, 'comments_write_enabled' => true, 'policies' => [$hash => $policy]]]);
        $draft = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
        $payload = $draft->payload;
        $payload['review_workspace'] = $this->version->payload['review_workspace'];
        $payload['review_workspace_collaboration'] = ['schema_version' => 1, 'policy_sha256' => $hash,
            'expires_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        $draft->payload = $payload;
        $draft->save();
        $draft->refresh();
        $published = app(ProposalService::class)->publish($this->tenant, $this->owner->id, $draft->id, $draft->revision,
            'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $this->initialize();
    }

    private function reader(): Request
    {
        $request = $this->recipient();
        $request->session()->put('service_proposal_name.'.$this->version->id, $this->version->payload['reviewer_names'][0]);

        return $request;
    }

    private function target(string $kind = 'ticket', ?string $id = null): array
    {
        return ['kind' => $kind, 'id' => $id ?? $this->ids['incoming']];
    }

    private function commentBody(Request $request, array $replace = []): array
    {
        return $replace + ['binding' => $this->binding(), 'selection' => $this->selection(), 'target' => $this->target(),
            'request_id' => (string) Str::uuid(), 'expected_target_version' => 1, 'expected_thread_revision' => 0,
            'expected_reviewer_name' => $request->session()->get('service_proposal_name.'.$this->version->id), 'body' => 'PERSISTED PRIVATE REVIEW NOTE <script>literal only</script>'];
    }

    private function show(Request $request, ?array $target = null, ?array $selection = null): array
    {
        return app(ReviewerWorkspaceDiscussion::class)->show($request, $this->token, $this->binding(), $selection ?? $this->selection(), $target ?? $this->target());
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unsafe collaboration request was admitted.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_two_legitimate_sessions_reload_shared_comments_without_native_tasks_memberships_or_budget_mutation(): void
    {
        $one = $this->reader();
        $two = $this->reader();
        $before = [\Illuminate\Support\Facades\Schema::hasTable('team_tasks'), DB::table('users')->count(), DB::table('tenant_user')->get()->toJson(),
            DB::table('team_area_members')->get()->toJson(), (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first()];
        $this->assertSame([], $this->show($one)['comments']);
        $body = $this->commentBody($one);
        $result = app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token, $body);
        $this->assertSame('applied', $result['status']);
        $seen = $this->show($two);
        $this->assertSame($body['body'], $seen['comments'][0]['body']);
        $this->assertFalse($seen['comments'][0]['mine']);
        $this->assertTrue($this->show($one)['comments'][0]['mine']);
        $this->assertSame('self_selected_display_name', $seen['comments'][0]['identity_status']);
        $this->assertFalse($seen['files_available']);
        $this->assertStringNotContainsString('capability_hash', json_encode($seen));
        $raw = DB::table('team_operation_records')->where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->first();
        $this->assertStringNotContainsString('PERSISTED PRIVATE REVIEW NOTE', $raw->data);
        $this->assertSame($before, [\Illuminate\Support\Facades\Schema::hasTable('team_tasks'), DB::table('users')->count(), DB::table('tenant_user')->get()->toJson(),
            DB::table('team_area_members')->get()->toJson(), (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first()]);
        $this->assertNull($one->user());
        $this->assertSame(1, app(ReviewerWorkspace::class)->projection($two, $this->token, $this->binding(), $this->selection())['revision']);
    }

    public function test_once_only_replay_status_and_name_ownership_are_exact_and_a_different_browser_cannot_claim_a_receipt(): void
    {
        $one = $this->reader();
        $two = $this->reader();
        $service = app(ReviewerWorkspaceDiscussion::class);
        $body = $this->commentBody($one);
        $digest = ReviewerWorkspaceSchema::digest($body);
        $receipt = $service->comment($one, $this->token, $body)['receipt'];
        $this->assertTrue($service->comment($one, $this->token, $body)['replayed']);
        $this->assertSame($receipt, $service->status($one, $this->token, $this->binding(), $this->selection(), $this->target(), $body['request_id'], $digest)['receipt']);
        $this->assertSame(['status' => 'not_observed', 'settled' => false], $service->status($two, $this->token, $this->binding(), $this->selection(), $this->target(), $body['request_id'], $digest));
        $this->denied(fn () => $service->comment($one, $this->token, [...$body, 'body' => 'Altered same key']), 409);
        $this->assertCount(1, $this->show($two)['comments']);
        $changedName = $this->commentBody($one, ['expected_thread_revision' => 1, 'expected_reviewer_name' => 'Unselected reviewer']);
        $this->denied(fn () => $service->comment($one, $this->token, $changedName), 409);
        $this->assertCount(1, $this->show($one)['comments']);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::RECEIPT)->count());
    }

    public function test_threads_are_independent_exact_records_incoming_tickets_need_no_native_task_and_foreign_scope_is_refused(): void
    {
        $one = $this->reader();
        foreach ([$this->target(), $this->target('task', $this->ids['task']), $this->target('project', $this->ids['project'])] as $target) {
            app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token, $this->commentBody($one, ['target' => $target]));
            $this->assertCount(1, $this->show($one, $target)['comments']);
        }
        $this->assertSame(3, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->count());
        $this->denied(fn () => $this->show($one, $this->target('project', $this->ids['other_project'])), 404);
        $this->denied(fn () => $this->show($one, ['kind' => 'native_task', 'id' => $this->ids['task']]), 422);
        $this->denied(fn () => $this->show($one, $this->target('task', (string) Str::uuid())), 404);
    }

    public function test_routing_ticket_moves_discussion_visibility_with_current_exact_graph_without_losing_its_thread(): void
    {
        $one = $this->reader();
        app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token, $this->commentBody($one));
        $selection = ['persona' => 'demo_manager', 'area_ids' => [$this->ids['a'], $this->ids['b']], 'person_id' => null];
        app(ReviewerWorkspace::class)->command($one, $this->token, $this->command(['selection' => $selection, 'action' => 'ticket.plan',
            'record_id' => $this->ids['incoming'], 'payload' => ['target_area_id' => $this->ids['b'], 'project_id' => $this->ids['other_project'],
                'project_version' => 1, 'task_id' => (string) Str::uuid(), 'assignee_id' => 'p2', 'building_id' => $this->ids['other_building'],
                'building_version' => 1, 'acknowledge_routing' => true]]));
        $this->denied(fn () => $this->show($one), 404);
        $moved = app(ReviewerWorkspaceDiscussion::class)->show($one, $this->token, $this->binding(),
            ['persona' => 'demo_manager', 'area_ids' => [$this->ids['b']], 'person_id' => null], $this->target());
        $this->assertCount(1, $moved['comments']);
        $this->assertSame(2, $moved['target_version']);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->count());
    }

    public function test_comments_page_explicitly_and_read_does_not_create_threads_or_mutate_graph(): void
    {
        $one = $this->reader();
        $service = app(ReviewerWorkspaceDiscussion::class);
        $this->show($one);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->count());
        for ($index = 0; $index < 26; $index++) {
            $service->comment($one, $this->token, $this->commentBody($one, ['expected_thread_revision' => $index, 'body' => 'Saved review note '.$index]));
        }
        $page = $this->show($one);
        $this->assertCount(25, $page['comments']);
        $this->assertNotNull($page['before']);
        $older = $service->show($one, $this->token, $this->binding(), $this->selection(), $this->target(), $page['before']);
        $this->assertCount(1, $older['comments']);
        $this->assertSame('Saved review note 0', $older['comments'][0]['body']);
        $this->assertNull($older['before']);
        $this->assertSame(1, app(ReviewerWorkspace::class)->projection($one, $this->token, $this->binding(), $this->selection())['revision']);
    }

    public function test_closed_input_and_thread_limit_never_truncate_or_create_an_extra_receipt(): void
    {
        $one = $this->reader();
        $service = app(ReviewerWorkspaceDiscussion::class);
        $this->denied(fn () => $service->comment($one, $this->token, [...$this->commentBody($one), 'native_user_id' => $this->owner->id]), 422);
        $service->comment($one, $this->token, $this->commentBody($one));
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->firstOrFail();
        $data = $row->data;
        $example = $data['payload']['comments'][0];
        $data['payload']['comments'] = [];
        // Explicit bounded source fixture, not a claim that the application silently adds records.
        for ($i = 0; $i < 100; $i++) {
            $data['payload']['comments'][] = [...$example, 'id' => (string) Str::uuid()];
        }
        $row->data = $data;
        $row->save();
        $this->denied(fn () => $service->comment($one, $this->token, $this->commentBody($one, ['expected_thread_revision' => 1])), 429);
        $this->assertCount(100, $row->fresh()->data['payload']['comments']);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::RECEIPT)->count());
    }

    public function test_serialized_workspace_quota_counts_json_escaping_exactly_before_commit(): void
    {
        $one = $this->reader();
        $workspaceRow = TeamOperationRecord::findOrFail($this->version->payload['review_workspace']['workspace_id']);
        $workspaceData = $workspaceRow->data;
        for ($i = 2; $i <= 17; $i++) {
            $workspaceData['graph']['tasks'][] = [...$workspaceData['graph']['tasks'][0], 'id' => sprintf('84000000-0000-4000-8000-%012d', $i)];
        }
        $workspaceData['graph'] = app(ReviewerWorkspaceSchema::class)->graph($workspaceData['graph']);
        $workspaceRow->data = $workspaceData;
        $workspaceRow->save();
        $service = app(ReviewerWorkspaceDiscussion::class);
        $target = $this->target('task', $this->ids['task']);
        $service->comment($one, $this->token, $this->commentBody($one, ['target' => $target]));
        $first = TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->sole();
        $base = $first->data;
        $example = [...$base['payload']['comments'][0], 'name' => 'Reviewer'];
        $body = 'a'.str_repeat("\n", 3999);
        $remaining = 508;
        // Adversarial, valid bounded fixture rows. No application seeding or real reviewer history is inferred.
        foreach ($workspaceData['graph']['tasks'] as $i => $task) {
            $row = $i === 0 ? $first : $first->replicate(['id']);
            if ($i !== 0) {
                $row->id = (string) Str::uuid();
            }
            $data = $base;
            $data['target'] = ['kind' => 'task', 'id' => $task['id']];
            $data['payload']['comments'] = [];
            $count = $i === 0 ? 12 : min(31, $remaining);
            $remaining -= $count;
            for ($j = 0; $j < $count; $j++) {
                $data['payload']['comments'][] = [...$example, 'id' => (string) Str::uuid(), 'body' => $body];
            }
            $this->assertLessThanOrEqual(262144, strlen(ReviewerWorkspaceSchema::canonical($data['payload']['comments'])));
            $row->data = $data;
            $row->source_key = ReviewerWorkspaceSchema::digest(['thread' => $data['workspace_id'], 'version' => $this->version->id, 'target' => $data['target']]);
            $row->save();
        }
        $this->assertSame(0, $remaining);
        $bytes = fn () => TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->get()
            ->sum(fn ($row) => strlen(ReviewerWorkspaceSchema::canonical($row->data['payload']['comments'])));
        $before = $bytes();
        $receipts = TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::RECEIPT)->count();
        $this->assertLessThan(4194304, $before);
        $this->denied(fn () => $service->comment($one, $this->token,
            $this->commentBody($one, ['target' => $target, 'expected_thread_revision' => 1, 'body' => $body])), 429);
        $this->assertSame($before, $bytes());
        $this->assertSame($receipts, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::RECEIPT)->count());
    }

    public function test_all_comment_routes_preserve_pre_session_load_locks_for_current_name_grant_and_nonce(): void
    {
        foreach (['show', 'comment', 'status'] as $action) {
            $route = app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.collaboration.'.$action);
            $this->assertNotNull($route);
            $this->assertSame(10, $route->locksFor());
            $this->assertSame(10, $route->waitsFor());
        }
        $this->assertSame(10, app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.projection')->locksFor());
    }

    public function test_client_descriptor_is_server_owned_private_and_absent_grant_cannot_read_or_comment(): void
    {
        $one = $this->reader();
        $descriptor = app(\App\Proposals\ReviewerWorkspaceCollaborationAccess::class)->clientDescriptor($one, $this->token, $this->binding(), $this->selection());
        $this->assertSame($this->version->payload['review_workspace']['workspace_id'], $descriptor['workspace_id']);
        $this->assertSame($one->session()->token(), $descriptor['csrf']);
        $this->assertCount(3, $descriptor['endpoints']);
        foreach ($descriptor['endpoints'] as $action => $url) {
            $this->assertSame('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/collaboration/'.$action, $url);
        }
        $one->session()->forget('service_proposal_access.'.$this->version->id);
        $this->denied(fn () => $this->show($one), 403);
        $this->denied(fn () => app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token, $this->commentBody($one)), 403);
    }

    public function test_worker_requester_and_readonly_personas_keep_actual_projector_permissions(): void
    {
        $one = $this->reader();
        $service = app(ReviewerWorkspaceDiscussion::class);
        $task = $this->target('task', $this->ids['task']);
        $body = $this->commentBody($one, ['selection' => $this->selection('worker', 'p1'), 'target' => $task]);
        $this->assertSame('applied', $service->comment($one, $this->token, $body)['status']);
        $this->denied(fn () => $service->comment($one, $this->token, $this->commentBody($one,
            ['selection' => $this->selection('worker', 'p1'), 'target' => $this->target('project', $this->ids['project'])])), 403);
        $this->denied(fn () => $this->show($one, $task, $this->selection('requester')), 404);
        $this->assertFalse($this->show($one, $task, $this->selection('executive'))['can_comment']);
        $this->denied(fn () => $service->comment($one, $this->token, $this->commentBody($one,
            ['selection' => $this->selection('executive'), 'target' => $task, 'expected_thread_revision' => 1])), 403);
    }

    public function test_archived_parent_closes_linked_ticket_writes_for_manager_worker_and_redacted_requester(): void
    {
        $one = $this->reader();
        $workspace = app(ReviewerWorkspace::class);
        $created = (string) Str::uuid();
        $workspace->command($one, $this->token, $this->command(['selection' => $this->selection('requester'), 'action' => 'ticket.create',
            'record_id' => $created, 'expected_record_version' => 0,
            'payload' => ['area_id' => $this->ids['a'], 'title' => 'Own fictional review ticket', 'description' => '', 'priority' => 'normal',
                'due_on' => null, 'building_id' => $this->ids['building'], 'building_version' => 1, 'type' => 'general']]));
        $workspace->command($one, $this->token, $this->command(['expected_revision' => 2, 'action' => 'ticket.assign', 'record_id' => $created,
            'payload' => ['target_area_id' => $this->ids['a'], 'project_id' => $this->ids['project'], 'project_version' => 1,
                'task_id' => (string) Str::uuid(), 'assignee_id' => 'p1', 'building_id' => $this->ids['building'],
                'building_version' => 1, 'acknowledge_routing' => true]]));
        $workspace->command($one, $this->token, $this->command(['expected_revision' => 3, 'action' => 'project.archive', 'payload' => ['archived' => true]]));
        $this->assertFalse($this->show($one, $this->target('task', $this->ids['task']))['can_comment']);
        foreach ([$this->selection(), $this->selection('worker', 'p1'), $this->selection('requester')] as $selected) {
            $target = $this->target('ticket', $created);
            $this->assertFalse($this->show($one, $target, $selected)['can_comment']);
            $this->denied(fn () => app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token,
                $this->commentBody($one, ['target' => $target, 'selection' => $selected, 'expected_target_version' => 2])), 403);
        }
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->count());
    }

    public function test_disabled_or_unapproved_feature_and_expired_publication_never_leak_or_fallback(): void
    {
        $one = $this->reader();
        app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token, $this->commentBody($one));
        config(['service_proposals.reviewer_workspace.collaboration.enabled' => false]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->denied(fn () => $this->show($one), 404);
        $this->assertSame([], $queries);
        config(['service_proposals.reviewer_workspace.collaboration.enabled' => true]);
        $this->version->revoked_at = now();
        $this->version->save();
        $this->denied(fn () => $this->show($one), 410);
    }

    public function test_stale_thread_is_fixed_definitive_rejection_only_before_write_and_current_policy_changes_revoke_access(): void
    {
        $one = $this->reader();
        $two = $this->reader();
        $service = app(ReviewerWorkspaceDiscussion::class);
        $service->comment($one, $this->token, $this->commentBody($one));
        $this->denied(fn () => $service->comment($two, $this->token, $this->commentBody($two)), 409);
        $this->assertCount(1, $this->show($two)['comments']);
        config(['service_proposals.reviewer_workspace.collaboration.comments_write_enabled' => false]);
        $this->assertFalse($this->show($two)['can_comment']);
        $this->denied(fn () => $service->comment($two, $this->token, $this->commentBody($two, ['expected_thread_revision' => 1])), 403);
        $policyHash = $this->version->payload['review_workspace_collaboration']['policy_sha256'];
        config(['service_proposals.reviewer_workspace.collaboration.policies.'.$policyHash.'.uploads_enabled' => true]);
        $this->denied(fn () => $this->show($one), 503);
    }

    public function test_private_controller_requires_real_csrf_origin_and_sanitizes_failures(): void
    {
        $one = $this->reader();
        $body = $this->commentBody($one);
        $request = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/collaboration/comment', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], content: json_encode($body));
        $request->setLaravelSession($one->session());
        $controller = app(ReviewerWorkspaceCollaborationController::class);
        $this->assertSame(419, $controller->comment($request, $this->token)->getStatusCode());
        $request->headers->set('X-CSRF-TOKEN', $request->session()->token());
        $response = $controller->comment($request, $this->token);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('applied', $response->getData(true)['status']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $request->headers->set('Origin', 'https://foreign.invalid');
        $this->assertSame(403, $controller->comment($request, $this->token)->getStatusCode());
        $this->assertStringNotContainsString('trace', $controller->comment($request, $this->token)->getContent());
    }

    public function test_precontroller_boundary_keeps_all_method_csrf_runtime_and_throttle_statuses_private(): void
    {
        foreach (['show', 'comment', 'status'] as $action) {
            foreach ([405, 419, 429, 503] as $status) {
                $request = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/work%73pace/collaboration/'.$action.'/', 'GET');
                $response = (new ReviewerAiBoundary)->handle($request, fn () => throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'PRIVATE TRACE SENTINEL'));
                $this->assertSame($status, $response->getStatusCode());
                $this->assertStringNotContainsString('SENTINEL', $response->getContent());
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        }
    }

    public function test_real_owner_dry_run_then_prune_erases_expired_comments_with_flags_off_but_retains_receipts(): void
    {
        $one = $this->reader();
        app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token, $this->commentBody($one));
        $owner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $pruner = app(ReviewerWorkspaceCollaborationPruner::class);
        $this->assertSame(0, $pruner->prune($owner)['eligible']);
        $this->version->revoked_at = now();
        $this->version->save();
        config(['service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.reviewer_workspace.collaboration.enabled' => false]);
        $this->assertSame(1, $pruner->prune($owner)['eligible']);
        $this->assertSame(0, $pruner->prune($owner)['erased']);
        $this->assertSame(1, $pruner->prune($owner, dryRun: false)['erased']);
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->firstOrFail();
        $this->assertSame(['comments' => []], $row->data['payload']);
        $this->assertSame('expired', $row->status);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::RECEIPT)->count());
        $this->assertSame(0, $pruner->prune($owner, dryRun: false)['erased']);
        $anonymousOwner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $anonymousOwner->setUserResolver(fn () => null);
        $this->denied(fn () => $pruner->prune($anonymousOwner), 403);
    }

    public function test_actual_controller_serializes_a_valid_large_unicode_thread_without_truncation(): void
    {
        $one = $this->reader();
        $body = str_repeat('🌲', 4000);
        for ($index = 0; $index < 14; $index++) {
            app(ReviewerWorkspaceDiscussion::class)->comment($one, $this->token,
                $this->commentBody($one, ['expected_thread_revision' => $index, 'body' => $body]));
        }
        $request = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/collaboration/show', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'],
            content: json_encode(['binding' => $this->binding(), 'selection' => $this->selection(), 'target' => $this->target(), 'before' => null]));
        $request->setLaravelSession($one->session());
        $request->headers->set('X-CSRF-TOKEN', $request->session()->token());
        $response = app(ReviewerWorkspaceCollaborationController::class)->show($request, $this->token);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThan(262144, strlen($response->getContent()));
        $this->assertLessThan(2 * 1024 * 1024, strlen($response->getContent()));
        $this->assertCount(14, $response->getData(true)['comments']);
        $this->assertSame($body, $response->getData(true)['comments'][13]['body']);
        $this->assertSame(1, app(ReviewerWorkspace::class)->projection($one, $this->token, $this->binding(), $this->selection())['revision']);
    }
}
