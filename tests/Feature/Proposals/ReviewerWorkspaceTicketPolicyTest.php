<?php

namespace Tests\Feature\Proposals;

use App\Proposals\ReviewerWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

final class ReviewerWorkspaceTicketPolicyTest extends ReviewerWorkspaceFixture
{
    private function rows(): string
    {
        return DB::table('team_operation_records')->orderBy('id')->get()->toJson();
    }

    private function refuse(callable $operation, int $status): void
    {
        $observed = null;
        try {
            $operation();
        } catch (HttpExceptionInterface $error) {
            $observed = $error->getStatusCode();
        }
        $this->assertSame($status, $observed);
    }

    private function taskPayload(array $changes = []): array
    {
        return $changes + ['title' => 'Fictional task title edit', 'description' => 'Unrelated task details', 'priority' => 'high',
            'assignee_id' => 'p1', 'building_id' => $this->ids['building'], 'building_version' => 1, 'due_on' => '2026-11-02'];
    }

    public function test_non_admin_ticket_actions_are_denied_without_graph_audit_or_receipt_writes_and_projection_matches(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $request = $this->recipient();
        $before = $this->rows();
        foreach (['manager', 'requester', 'worker'] as $persona) {
            $selection = $this->selection($persona, $persona === 'worker' ? 'p1' : null);
            $projection = $service->projection($request, $this->token, $this->binding(), $selection);
            $this->assertContains('ticket.create', $projection['permitted_actions']);
            foreach (['ticket.update', 'ticket.classify', 'ticket.assign', 'ticket.plan', 'ticket.status', 'ticket.reorder'] as $action) {
                $this->assertNotContains($action, $projection['permitted_actions']);
                foreach ($projection['graph']['tickets'] as $ticket) {
                    $this->assertNotContains($action, $ticket['allowed_actions']);
                }
                $this->refuse(fn () => $service->command($request, $this->token, $this->command(['selection' => $selection,
                    'action' => $action, 'record_id' => $this->ids['incoming'], 'payload' => []])), 403);
                $this->assertSame($before, $this->rows());
            }
        }
        $admin = $service->projection($request, $this->token, $this->binding(), $this->selection());
        $this->assertContains('ticket.update', $admin['graph']['tickets'][1]['allowed_actions']);
        $result = $service->command($request, $this->token, $this->command(['action' => 'ticket.classify', 'record_id' => $this->ids['incoming'],
            'payload' => ['type' => 'maintenance', 'priority' => 'urgent']]));
        $this->assertSame('urgent', collect($result['projection']['graph']['tickets'])->firstWhere('id', $this->ids['incoming'])['priority']);
    }

    public function test_requester_create_replay_and_owned_read_survive_but_postsubmit_edit_does_not(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $request = $this->recipient();
        $id = (string) Str::uuid();
        $payload = ['area_id' => $this->ids['a'], 'title' => 'Own fictional ticket', 'description' => 'Submitted details', 'priority' => 'normal',
            'due_on' => null, 'building_id' => $this->ids['building'], 'building_version' => 1, 'type' => 'general'];
        $create = $this->command(['selection' => $this->selection('requester'), 'action' => 'ticket.create', 'record_id' => $id,
            'expected_record_version' => 0, 'payload' => $payload]);
        $created = $service->command($request, $this->token, $create);
        $this->assertSame([], $created['projection']['graph']['tickets'][0]['allowed_actions']);
        $this->assertTrue($created['projection']['graph']['tickets'][0]['owned_by_caller']);
        $before = $this->rows();
        $this->assertTrue($service->command($request, $this->token, $create)['replayed']);
        $this->assertSame($before, $this->rows());
        unset($payload['area_id']);
        $edit = $this->command(['selection' => $this->selection('requester'), 'action' => 'ticket.update', 'record_id' => $id,
            'expected_revision' => 2, 'payload' => [...$payload, 'title' => 'Changed submitted title']]);
        $this->refuse(fn () => $service->command($request, $this->token, $edit), 403);
        $this->assertSame($before, $this->rows());
        $edit['selection'] = $this->selection();
        $result = $service->command($request, $this->token, $edit);
        $this->assertSame('Changed submitted title', collect($result['projection']['graph']['tickets'])->firstWhere('id', $id)['title']);
        $after = $this->rows();
        $edit['selection'] = $this->selection('manager');
        $edit['request_id'] = (string) Str::uuid();
        $edit['expected_revision'] = 3;
        $edit['expected_record_version'] = 2;
        $this->refuse(fn () => $service->command($request, $this->token, $edit), 403);
        $this->assertSame($after, $this->rows());
    }

    public function test_linked_task_sensitive_changes_are_atomic_but_unchanged_fields_allow_manager_task_edits_and_replay(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $request = $this->recipient();
        $before = $this->rows();
        foreach ([['priority' => 'urgent'], ['assignee_id' => null], ['building_id' => null, 'building_version' => null]] as $change) {
            $command = $this->command(['selection' => $this->selection('manager'), 'action' => 'task.update', 'record_id' => $this->ids['task'],
                'payload' => $this->taskPayload($change)]);
            $this->refuse(fn () => $service->command($request, $this->token, $command), 403);
            $this->assertSame($before, $this->rows());
        }
        $assign = $this->command(['selection' => $this->selection('manager'), 'action' => 'task.assign', 'record_id' => $this->ids['task'], 'payload' => ['assignee_id' => null]]);
        $this->refuse(fn () => $service->command($request, $this->token, $assign), 403);
        $this->assertSame($before, $this->rows());
        $edit = $this->command(['selection' => $this->selection('manager'), 'action' => 'task.update', 'record_id' => $this->ids['task'], 'payload' => $this->taskPayload()]);
        $result = $service->command($request, $this->token, $edit);
        $this->assertSame('Fictional task title edit', $result['projection']['graph']['tasks'][0]['title']);
        $this->assertSame('2026-11-02', $result['projection']['graph']['tasks'][0]['due_on']);
        $this->assertSame('high', $result['projection']['graph']['tasks'][0]['priority']);
        $this->assertSame('Fictional leak ticket', $result['projection']['graph']['tickets'][0]['title']);
        $after = $this->rows();
        $this->assertTrue($service->command($request, $this->token, $edit)['replayed']);
        $this->assertSame($after, $this->rows());
        $stale = $edit;
        $stale['request_id'] = (string) Str::uuid();
        $this->refuse(fn () => $service->command($request, $this->token, $stale), 409);
        $this->assertSame($after, $this->rows());
    }

    public function test_admin_linked_edits_unlinked_manager_work_and_assigned_worker_status_keep_existing_authority(): void
    {
        $this->initialize();
        $service = app(ReviewerWorkspace::class);
        $request = $this->recipient();
        $admin = $service->command($request, $this->token, $this->command(['action' => 'task.update', 'record_id' => $this->ids['task'],
            'payload' => $this->taskPayload(['priority' => 'urgent'])]));
        $this->assertSame('urgent', $admin['projection']['graph']['tasks'][0]['priority']);
        $status = $service->command($request, $this->token, $this->command(['selection' => $this->selection('worker', 'p1'),
            'action' => 'task.status', 'record_id' => $this->ids['task'], 'expected_revision' => 2, 'expected_record_version' => 2, 'payload' => ['status' => 'completed']]));
        $this->assertSame('completed', $status['projection']['graph']['tasks'][0]['status']);
        $this->assertSame('completed', $status['projection']['graph']['tickets'][0]['status']);
        $id = (string) Str::uuid();
        $payload = $this->taskPayload(['area_id' => $this->ids['a'], 'project_id' => $this->ids['project']]);
        $service->command($request, $this->token, $this->command(['selection' => $this->selection('manager'), 'action' => 'task.create',
            'record_id' => $id, 'expected_revision' => 3, 'expected_record_version' => 0, 'payload' => $payload]));
        $result = $service->command($request, $this->token, $this->command(['selection' => $this->selection('manager'), 'action' => 'task.update',
            'record_id' => $id, 'expected_revision' => 4, 'payload' => $this->taskPayload(['priority' => 'low', 'assignee_id' => null])]));
        $this->assertSame('low', collect($result['projection']['graph']['tasks'])->firstWhere('id', $id)['priority']);
        $this->assertNull(collect($result['projection']['graph']['tasks'])->firstWhere('id', $id)['assignee_id']);
    }
}
