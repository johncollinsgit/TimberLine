<?php

namespace App\Services\Team;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Canonical persistent graph. V1 serializes commands per tenant; all projections remain object-authorized. */
class TeamWorkRepository
{
    public function state(int $tenantId, bool $history = true): array
    {
        abort_unless($tenantId > 0, 403);
        $state = WorkGraph::empty();
        foreach (DB::table('team_projects')->where('tenant_id', $tenantId)->get() as $row) {
            $state['projects'][$row->id] = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) + ['members' => []];
        }
        foreach (DB::table('team_project_members')->where('tenant_id', $tenantId)->get() as $row) {
            if (isset($state['projects'][$row->project_id])) {
                $state['projects'][$row->project_id]['members'][(string) $row->user_id] = $row->access;
            }
        }
        foreach (DB::table('team_tasks')->where('tenant_id', $tenantId)->get() as $row) {
            $state['tasks'][$row->id] = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) + ['memberships' => []];
        }
        foreach (DB::table('team_task_projects')->where('tenant_id', $tenantId)->get() as $row) {
            if (isset($state['tasks'][$row->task_id], $state['projects'][$row->project_id])) {
                $state['tasks'][$row->task_id]['memberships'][$row->project_id] = ['section_id' => $row->section_id, 'position' => (int) $row->position];
            }
        }
        if (! $history) {
            return $state;
        }
        foreach (DB::table('team_work_events')->where('tenant_id', $tenantId)->orderBy('id')->get() as $row) {
            $state['events'][] = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (DB::table('team_work_receipts')->where('tenant_id', $tenantId)->get() as $row) {
            $state['receipts'][$row->receipt_key] = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        }

        return $state;
    }

    public function execute(int $tenantId, callable $resolveActor, array $command): array
    {
        abort_unless($tenantId > 0, 403);

        return DB::transaction(function () use ($tenantId, $resolveActor, $command): array {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenantId]);
            DB::table('team_work_locks')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $actor = $resolveActor(); // Re-read membership and module access inside mutation transaction.
            abort_unless($actor['tenant_id'] === (string) $tenantId, 403);
            $before = $this->state($tenantId);
            $result = app(WorkGraph::class)->execute($before, $actor, $command, now()->toIso8601String());
            $this->save($tenantId, $before, $result['state']);
            app(\App\Services\Team\Search\SearchDocuments::class)->graph($tenantId, $before, $result['state']);
            if (! $result['replayed'] && ! config('enterprise.runtime')) {
                $this->notifications($tenantId, $actor, $command, $before, $result['state']);
            }
            unset($result['state']);

            return $result;
        }, 3);
    }

    private function notifications(int $tenantId, array $actor, array $command, array $before, array $after): void
    {
        $task = $after['tasks'][$command['id']] ?? null;
        if (! $task) {
            return;
        }
        $recipients = [];
        if (in_array($command['kind'], ['task.create', 'task.update', 'task.follow_up'], true) && $task['assignee_id'] !== null
            && ($before['tasks'][$task['id']]['assignee_id'] ?? null) !== $task['assignee_id']) {
            $recipients[$task['assignee_id']] = 'task.assigned';
        }
        if ($command['kind'] === 'task.comment') {
            foreach ($command['mentioned_user_ids'] ?? [] as $id) {
                $recipients[(string) $id] = 'comment.mentioned';
            }
        }
        if ($command['kind'] === 'task.complete' && $task['completed_at'] !== null
            && ($before['tasks'][$task['id']]['completed_at'] ?? null) === null) {
            foreach ($after['tasks'] as $dependent) {
                if (! isset($dependent['blocker_ids'][$task['id']]) || $dependent['deleted'] || $dependent['completed_at'] !== null || $dependent['assignee_id'] === null) {
                    continue;
                }
                $userId = $dependent['assignee_id'];
                $recipient = array_replace($actor, ['user_id' => $userId, 'role' => $actor['active_roles'][$userId] ?? 'guest']);
                if (! in_array($userId, $actor['active_user_ids'], true) || $userId === $actor['user_id']
                    || ! DB::table('users')->where('id', $userId)->whereNotNull('email_verified_at')->exists()
                    || ! app(WorkGraph::class)->can($after, $recipient, 'task', $task['id'])
                    || ! app(WorkGraph::class)->can($after, $recipient, 'task', $dependent['id'])) {
                    continue;
                }
                if (app(WorkGraph::class)->read($after, $recipient, 'task', $dependent['id'])['is_blocked']) {
                    continue;
                }
                // Internal Inbox only; no queue, push, email or provider dispatch.
                DB::table('team_inbox_items')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                    'recipient_user_id' => $userId, 'actor_user_id' => $actor['user_id'], 'task_id' => $dependent['id'], 'event_type' => 'dependency.unblocked',
                    'event_key' => hash('sha256', json_encode([$actor['user_id'], $command['idempotency_key'], $dependent['id'], 'dependency.unblocked'])),
                    'read_at' => null, 'archived_at' => null, 'created_at' => now()]);
            }
        }
        foreach ($recipients as $userId => $eventType) {
            $userId = (string) $userId;
            if ($userId === $actor['user_id'] || ! in_array($userId, $actor['active_user_ids'], true)) {
                continue;
            }
            if (! DB::table('users')->where('id', $userId)->whereNotNull('email_verified_at')->exists()) {
                continue;
            }
            $recipient = array_replace($actor, ['user_id' => $userId, 'role' => $actor['active_roles'][$userId] ?? 'guest']);
            if (! app(WorkGraph::class)->can($after, $recipient, 'task', $task['id'])) {
                continue;
            }
            DB::table('team_inbox_items')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'recipient_user_id' => $userId, 'actor_user_id' => $actor['user_id'], 'task_id' => $task['id'], 'event_type' => $eventType,
                'event_key' => hash('sha256', json_encode([$actor['user_id'], $command['idempotency_key'], $task['id'], $eventType])),
                'read_at' => null, 'archived_at' => null, 'created_at' => now()]);
        }
    }

    private function save(int $tenantId, array $before, array $after): void
    {
        foreach ($after['projects'] as $id => $project) {
            if (($before['projects'][$id] ?? null) === $project) {
                continue;
            }
            $this->recordVersion($tenantId, 'project', $project);
            $payload = $project;
            unset($payload['members']);
            DB::table('team_projects')->updateOrInsert(['tenant_id' => $tenantId, 'id' => $id], [
                'owner_user_id' => $project['owner_id'], 'name' => $project['name'], 'privacy' => $project['privacy'],
                'archived' => $project['archived'], 'version' => $project['version'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
            DB::table('team_project_members')->where('tenant_id', $tenantId)->where('project_id', $id)->delete();
            foreach ($project['members'] as $userId => $access) {
                DB::table('team_project_members')->insert(['tenant_id' => $tenantId, 'project_id' => $id, 'user_id' => $userId, 'access' => $access]);
            }
        }
        foreach ($after['tasks'] as $id => $task) {
            if (($before['tasks'][$id] ?? null) === $task) {
                continue;
            }
            $this->recordVersion($tenantId, 'task', $task);
            $payload = $task;
            unset($payload['memberships']);
            DB::table('team_tasks')->updateOrInsert(['tenant_id' => $tenantId, 'id' => $id], [
                'assigned_user_id' => $task['assignee_id'], 'parent_id' => $task['parent_id'], 'title' => $task['title'], 'due_on' => $task['due_on'],
                'due_at' => $task['due_at'] ? CarbonImmutable::parse($task['due_at'])->utc()->format('Y-m-d H:i:s') : null,
                'completed_at' => $task['completed_at'] ? CarbonImmutable::parse($task['completed_at'])->utc()->format('Y-m-d H:i:s') : null,
                'deleted' => $task['deleted'], 'version' => $task['version'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
            DB::table('team_task_projects')->where('tenant_id', $tenantId)->where('task_id', $id)->delete();
            foreach ($task['memberships'] as $projectId => $membership) {
                DB::table('team_task_projects')->insert(['tenant_id' => $tenantId, 'task_id' => $id, 'project_id' => $projectId] + $membership);
            }
        }
        foreach (array_slice($after['events'], count($before['events'])) as $event) {
            DB::table('team_work_events')->insert(['tenant_id' => $tenantId, 'entity_type' => $event['entity_type'], 'entity_id' => $event['entity_id'], 'payload' => json_encode($event, JSON_THROW_ON_ERROR)]);
        }
        foreach (array_diff_key($after['receipts'], $before['receipts']) as $key => $receipt) {
            DB::table('team_work_receipts')->insert(['tenant_id' => $tenantId, 'receipt_hash' => hash('sha256', $key), 'receipt_key' => $key, 'payload' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        }
    }

    private function recordVersion(int $tenantId, string $type, array $entity): void
    {
        $snapshot = json_encode($entity, JSON_THROW_ON_ERROR);
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL normalizes JSON key order/spacing on storage. Hash the bytes it
            // will actually retain, so immutable history can be verified on reads.
            $snapshot = (string) DB::scalar('SELECT CAST(? AS JSON)', [$snapshot]);
        }
        DB::table('team_work_versions')->insert(['tenant_id' => $tenantId, 'entity_type' => $type, 'entity_id' => $entity['id'],
            'version' => $entity['version'], 'sha256' => hash('sha256', $snapshot), 'snapshot' => $snapshot, 'recorded_at' => now()]);
    }
}
