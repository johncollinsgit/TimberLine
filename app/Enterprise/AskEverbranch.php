<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;

class AskEverbranch
{
    public function __construct(private WorkspaceAccess $access, private RequestWorkflow $requests, private TeamWorkRepository $work, private WorkGraph $graph, private AskModel $model) {}

    public function context(Tenant $tenant, User $user, string $areaId, ?string $family = null): array
    {
        abort_unless(config('enterprise.ask_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $area = $this->access->area($tenant, $actor, $areaId);
        $sources = [];
        $make = function (string $kind, string $id, string $title, array $facts) use ($tenant, $areaId): array {
            return ['ref' => $kind.':'.$id, 'kind' => $kind, 'id' => $id, 'title' => mb_substr($title, 0, 100), 'facts' => $facts,
                'url' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $areaId,
                    'section' => $kind === 'request' ? 'requests' : ($kind === 'project' ? 'projects' : 'contractors'), 'selected' => $id])];
        };
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'enterprise_request'])
            ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        $partial = true; // Fixed bounded-coverage disclosure, never a hidden-record count signal.
        $visibleRequests = 0;
        foreach ($rows as $row) {
            try {
                $r = $this->requests->view($tenant, $actor, $row);
                $sources[] = $make('request', $r['id'], $r['title'], ['status' => $r['status'], 'due_on' => $r['due_on'], 'version' => $r['version'], 'updated_at' => $row->updated_at?->toIso8601String()]);
                if (++$visibleRequests === 100) {
                    break;
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            }
        }
        $state = $this->work->state($tenant->id);
        foreach ($state['projects'] as $id => $project) {
            if (($project['enterprise_area_id'] ?? null) === $areaId && $this->graph->can($state, $actor, 'project', $id)) {
                $r = $this->graph->read($state, $actor, 'project', $id);
                $sources[] = $make('project', $id, $r['name'], ['status' => $r['archived'] ? 'archived' : 'active', 'version' => $r['version']]);
            }
        }
        if (config('enterprise.planning_enabled')) {
            $planning = app(Planning::class)->listing($tenant, $user, $areaId);
            $partial = $partial || $planning['partial'];
            foreach ($planning['items'] as $row) {
                $facts = ['status' => $row['status'], 'version' => $row['version'], 'updated_at' => $row['updated_at']];
                if ($row['kind'] === 'schedule') {
                    $facts += ['starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on']];
                } elseif ($row['kind'] === 'cost') {
                    $facts += array_intersect_key($row['data'], array_flip(['amount_minor', 'currency', 'basis']));
                }
                $sources[] = $make($row['kind'], $row['id'], $row['title'], $facts);
            }
        }
        if ($family) {
            $sources = array_values(array_filter($sources, fn ($source) => $source['kind'] === $family));
            $sources = array_slice($sources, 0, 30);
        } else {
            $groups = [];
            foreach ($sources as $source) {
                $groups[$source['kind']][] = $source;
            }
            $sources = [];
            while (count($sources) < 30 && $groups !== []) {
                foreach ($groups as $kind => &$group) {
                    $sources[] = array_shift($group);
                    if ($group === []) {
                        unset($groups[$kind]);
                    }
                    if (count($sources) === 30) {
                        break;
                    }
                }
                unset($group);
            }
        }

        return ['identity' => ['id' => (string) $user->id, 'name' => $user->fresh()->name, 'role' => $actor['role']],
            'tenant_id' => (string) $tenant->id, 'department' => ['id' => $area->id, 'name' => $area->name], 'sources' => $sources,
            'partial' => $partial, 'as_of' => now()->toIso8601String(),
            'coverage' => 'Up to 30 currently authorized request, project and planning records in this workspace. Notes, file contents, mailbox messages, other departments, live schedules and accounting are not searched. Recorded dates/costs may be stale; this is not a total report.'];
    }

    public static function digest(array $context): string
    {
        unset($context['as_of']);

        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function ask(Tenant $tenant, User $user, string $areaId, string $question, ?string $focus = null): array
    {
        $family = $focus ? explode(':', $focus, 2)[0] : (preg_match('/\b(costs?|budgets?|spend|estimates?)\b/i', $question) ? 'cost'
            : (preg_match('/\b(schedules?|planned|dates)\b/i', $question) ? 'schedule'
                : (preg_match('/\bcontractors?\b/i', $question) ? 'contractor' : (preg_match('/\bprojects?\b/i', $question) ? 'project' : null))));
        $context = $this->context($tenant, $user, $areaId, $family);
        $refs = array_column($context['sources'], 'ref');
        if ($focus !== null) {
            abort_unless(in_array($focus, $refs, true), 404);
        }
        // No conversation text, HTML, generated links or record content can become a tool call.
        $chosen = $this->model->select($context, $question);
        $mode = $chosen === null ? 'record_backed' : 'model_selected_records';
        $wanted = $chosen ?? ($focus ? [$focus] : $refs);
        foreach ($wanted as $ref) {
            abort_unless(is_string($ref) && in_array($ref, $refs, true), 503, 'An unsupported source was returned.');
        }
        $sources = array_values(array_filter($context['sources'], fn ($s) => in_array($s['ref'], $wanted, true)));
        if ($focus === null && $chosen === null) {
            $kind = preg_match('/\b(cost|budget|spend|estimate)\b/i', $question) ? 'cost'
                : (preg_match('/\b(schedule|planned|dates)\b/i', $question) ? 'schedule'
                    : (preg_match('/\bcontractor\b/i', $question) ? 'contractor' : null));
            if ($kind) {
                $sources = array_values(array_filter($sources, fn ($s) => $s['kind'] === $kind));
            }
        }
        $sources = array_slice($sources, 0, 10);
        // A revocation or source edit during model processing must not return its old answer.
        abort_unless(hash_equals(self::digest($context), self::digest($this->context($tenant, $user, $areaId, $family))), 409, 'Access or records changed. Ask again using current data.');
        $reply = 'Here are the checked records available to you in '.$context['department']['name'].'.';
        if (! $sources) {
            $reply = 'No matching evidence is available in the checked sample. That does not establish that no work or cost exists.';
        }
        if (preg_match('/\b(send|email|delete|assign|approve|pay)\b/i', $question)) {
            $reply = 'Chat cannot execute actions. Email requires your own authorized mailbox, an exact draft preview and a separate confirmation. No message or work change was made.';
            $sources = [];
        }

        return ['reply' => $reply, 'mode' => $mode, 'sources' => $sources, 'identity' => $context['identity'], 'as_of' => $context['as_of'],
            'coverage' => $context['coverage'], 'partial' => $context['partial'],
            'model_status' => $chosen === null ? 'Model interpretation is not connected. These are current server-checked record facts.' : 'Model selected references; displayed facts and links are server checked.'];
    }
}
