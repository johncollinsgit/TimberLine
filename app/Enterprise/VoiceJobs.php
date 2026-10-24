<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only, record-backed voice lookup. No model, mailbox or messaging dispatch. */
class VoiceJobs
{
    public function __construct(private WorkspaceAccess $access, private Operations $operations, private RequestWorkflow $requests) {}

    public function lookup(Tenant $tenant, User $user, string $areaId, string $question, ?string $focus = null): array
    {
        abort_unless(config('enterprise.voice_jobs_enabled') && config('enterprise.ask_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $area = $this->access->area($tenant, $actor, $areaId);
        $base = ['mode' => 'record_backed', 'provider_dispatch' => false, 'sample_data' => (bool) config('enterprise.sample_data'), 'as_of' => now()->toIso8601String(),
            'scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $areaId, 'department' => $area->name],
            'coverage' => 'At most 200 currently authorized operational tasks in this department. Linked request location only; no live tracking, mailbox, student records or accounting. Missing evidence is not proof that nothing exists.',
            'candidates' => [], 'job' => null];
        if (preg_match('/\b(send|email|e-mail|text|sms|delete|assign|approve|pay|complete|reschedule|cancel)\b/iu', $question)) {
            return $base + ['outcome' => 'review_required', 'reply' => 'Voice lookup cannot change work or send messages. Open the authorized action or composer, review the exact recipient and body, and confirm separately. Nothing was sent or changed.'];
        }
        if ($focus !== null) {
            $task = $this->operations->show($tenant, $user, $focus);
            abort_unless($task['area_id'] === $areaId, 404);
        } else {
            // A narrow deterministic title matcher is labeled honestly, never as live AI.
            $terms = preg_split('/[^\pL\pN]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY);
            $stop = explode(' ', 'a an the please can could would you me my our show open find bring up tell about everything what whats is are where wheres who whos when how status job jobs task tasks work on at in of for to and with it its that this');
            $terms = array_values(array_diff(array_unique($terms), $stop));
            $items = $this->operations->listing($tenant, $user, $areaId)['items'];
            $matches = $terms === [] ? [] : array_values(array_filter($items, function ($task) use ($terms): bool {
                $title = mb_strtolower($task['title']);
                foreach ($terms as $term) {
                    if (! str_contains($title, $term)) {
                        return false;
                    }
                }

                return true;
            }));
            if (count($matches) !== 1) {
                $base['candidates'] = array_map(fn ($task) => ['id' => $task['id'], 'title' => $task['title']], array_slice($matches, 0, 10));

                return $base + ['outcome' => $matches === [] ? 'no_match' : 'choose', 'reply' => $matches === []
                    ? 'No matching job was found in the checked department sample. Say a few words from its title, or use search.'
                    : 'More than one permitted job matches. Choose the exact job before I open or describe it.'];
            }
            // Re-read the exact record through canonical current task permissions.
            $task = $this->operations->show($tenant, $user, $matches[0]['id']);
            abort_unless($task['area_id'] === $areaId, 404);
        }
        $location = null;
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'enterprise_request'])
            ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $row) {
            if (($row->data['task_id'] ?? null) !== $task['id']) {
                continue;
            }
            try {
                $request = $this->requests->view($tenant, $actor, $row);
                $location = ['label' => $request['data']['location'] ?? null,
                    'latitude' => $request['data']['latitude'] ?? null, 'longitude' => $request['data']['longitude'] ?? null,
                    'provenance' => 'Recorded request location; not verified positioning or live GPS.',
                    'source_url' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $areaId, 'section' => 'requests', 'selected' => $row->id])];
                break;
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }
        $people = $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')
            ->whereIn('users.id', array_filter([$task['owner_user_id'], $task['ball_user_id']]))->pluck('users.name', 'users.id');
        $job = array_intersect_key($task, array_flip(['id', 'project_id', 'area_id', 'title', 'status', 'priority', 'next_action', 'due_on', 'blockers', 'updated_at', 'version', 'task_version']));
        $job['owner'] = $people[$task['owner_user_id']] ?? null;
        $job['handler'] = $task['ball_team'] ? 'Department team' : ($people[$task['ball_user_id']] ?? null);
        $job['location'] = $location;
        $job['source_url'] = route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $areaId, 'section' => 'projects', 'selected' => $task['project_id'], 'task' => $task['id']]);
        $job['cost_coverage'] = 'Costs are outside this voice projection. Use currently authorized Planning for recorded project costs; no job total is inferred.';
        $base['job'] = $job;
        $reply = $job['title'].'. Status: '.str_replace('_', ' ', $job['status']).'. Location: '.($location['label'] ?? 'not recorded in the checked sources')
            .'. Current handler: '.($job['handler'] ?? 'not available').'. Due date: '.($job['due_on'] ?? 'not recorded')
            .'. Next action: '.($job['next_action'] ?: 'not recorded').'. Blockers: '.($job['blockers'] === [] ? 'none recorded in this task' : implode('; ', $job['blockers'])).'.';

        return $base + ['outcome' => 'matched', 'reply' => $reply];
    }
}
