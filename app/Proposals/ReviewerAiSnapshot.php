<?php

namespace App\Proposals;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Only explicit reviewer-supplied fictional fields. This never reads or establishes native campus authority. */
final class ReviewerAiSnapshot
{
    public const COVERAGE = 'Reviewer-supplied fictional browser snapshot. Selected project tasks and tickets only; no native campus records, notes, files, review comments, contact details, mailbox or financial records. Missing/bounded fields do not prove absence.';

    public const COVERAGE_V2 = 'Reviewer-supplied fictional browser snapshot. Selected projects, tasks, tickets, saved location IDs and project-level contractor plans and cost inputs only; not verified college facts or native authority. Costs are fictional estimates or recorded inputs, never a building allocation, approved budget, invoice or payment. Planned dates do not establish on-site presence. No notes, files, review comments, contacts or mailbox. Missing/bounded fields do not prove absence.';

    public const COVERAGE_SERVER = 'Server-held shared fictional workspace. Selected projects, tasks, tickets, saved location IDs and project-level contractor plans and cost inputs only; not verified college facts or native authority. Costs are fictional estimates or recorded inputs, never a building allocation, approved budget, invoice or payment. Planned dates do not establish on-site presence. No notes, files, review comments, contacts or mailbox. Missing/bounded fields do not prove absence.';

    public function normalize(array $input): array
    {
        if (($input['schema_version'] ?? null) === 2) {
            return $this->normalizeV2($input);
        }
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 24000, 422, 'Narrow the fictional snapshot before sharing.');
        Validator::make(['snapshot' => $input], ['snapshot' => ['required', 'array:schema_version,persona,local_revision,area_ids,sources,coverage'],
            'snapshot.schema_version' => ['required', 'integer', 'in:1'],
            'snapshot.persona' => ['required', Rule::in(['demo_manager', 'executive', 'manager', 'worker', 'requester'])],
            'snapshot.local_revision' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'snapshot.area_ids' => ['required', 'array', 'min:1', 'max:8'], 'snapshot.area_ids.*' => ['required', 'uuid', 'lowercase', 'distinct:strict'],
            'snapshot.sources' => ['present', 'array', 'max:32'], 'snapshot.sources.*' => ['required', 'array'],
            'snapshot.coverage' => ['required', Rule::in([self::COVERAGE])]])->validate();
        abort_unless(is_int($input['schema_version']) && is_int($input['local_revision']) && array_is_list($input['area_ids']) && array_is_list($input['sources']), 422);
        $areas = $input['area_ids'];
        sort($areas, SORT_STRING);
        $seen = [];
        $taskCount = 0;
        $sources = [];
        $statuses = ['submitted', 'triaged', 'assigned', 'in_progress', 'active', 'interrupted', 'awaiting_verification', 'completed', 'closed', 'on_track', 'on_hold', 'at_risk', 'archived', 'not_recorded'];
        foreach ($input['sources'] as $source) {
            $kind = $source['kind'] ?? null;
            abort_unless(in_array($kind, ['project', 'request'], true) && ($input['persona'] !== 'requester' || $kind === 'request'), 422);
            $keys = $kind === 'project' ? 'ref,kind,area_id,title,version,status,tasks,buildings' : 'ref,kind,area_id,title,version,status,priority,due_on,building';
            $rules = ['source' => ['required', 'array:'.$keys], 'source.ref' => ['required', 'string'], 'source.kind' => ['required', 'in:'.$kind],
                'source.area_id' => ['required', 'uuid', 'lowercase', Rule::in($areas)], 'source.title' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u'],
                'source.version' => ['required', 'integer', 'min:1'], 'source.status' => ['required', Rule::in($statuses)]];
            if ($kind === 'project') {
                $rules += ['source.tasks' => ['present', 'array', 'max:16'], 'source.tasks.*' => ['required', 'array:id,title,version,status,assigned_person,priority,due_on'],
                    'source.tasks.*.id' => ['required', 'uuid', 'lowercase', 'distinct:strict'], 'source.tasks.*.title' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u'],
                    'source.tasks.*.version' => ['required', 'integer', 'min:1'], 'source.tasks.*.status' => ['required', Rule::in($statuses)],
                    'source.tasks.*.assigned_person' => ['present', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u'],
                    'source.tasks.*.priority' => ['present', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
                    'source.tasks.*.due_on' => ['present', 'nullable', 'date_format:Y-m-d'],
                    'source.buildings' => ['present', 'array', 'max:8'], 'source.buildings.*' => ['string', 'max:200', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u']];
            } else {
                $rules += ['source.priority' => ['present', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
                    'source.due_on' => ['present', 'nullable', 'date_format:Y-m-d'], 'source.building' => ['present', 'string', 'max:200', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u']];
            }
            Validator::make(['source' => $source], $rules)->validate();
            abort_unless(is_int($source['version']) && preg_match('/^'.preg_quote($kind, '/').':([a-f0-9-]{36})$/D', $source['ref'], $match)
                && \Illuminate\Support\Str::isUuid($match[1]) && ! isset($seen[$source['ref']]), 422);
            $seen[$source['ref']] = true;
            if ($kind === 'project') {
                abort_unless(array_is_list($source['tasks']) && array_is_list($source['buildings']), 422);
                $taskCount += count($source['tasks']);
                abort_if($taskCount > 64, 422);
                foreach ($source['tasks'] as $task) {
                    abort_unless(is_int($task['version']), 422);
                }
            }
            // Rebuild in a fixed closed-key order; unknown fields never enter the receipt/provider payload.
            $closed = ['ref' => $source['ref'], 'kind' => $kind, 'area_id' => $source['area_id'],
                'title' => $source['title'], 'version' => $source['version'], 'status' => $source['status']];
            if ($kind === 'project') {
                $closed['tasks'] = array_map(fn ($task) => ['id' => $task['id'], 'title' => $task['title'],
                    'version' => $task['version'], 'status' => $task['status'], 'assigned_person' => $task['assigned_person'],
                    'priority' => $task['priority'], 'due_on' => $task['due_on']], $source['tasks']);
                $closed['buildings'] = $source['buildings'];
            } else {
                $closed += ['priority' => $source['priority'], 'due_on' => $source['due_on'], 'building' => $source['building']];
            }
            $sources[] = $closed;
        }
        usort($sources, fn ($left, $right) => strcmp($left['ref'], $right['ref']));

        return ['schema_version' => 1, 'persona' => $input['persona'], 'local_revision' => $input['local_revision'],
            'area_ids' => $areas, 'sources' => $sources, 'coverage' => self::COVERAGE];
    }

    private function normalizeV2(array $input): array
    {
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 24000, 422, 'Narrow the fictional snapshot before sharing.');
        Validator::make(['snapshot' => $input], ['snapshot' => ['required', 'array:schema_version,persona,local_revision,area_ids,sources,coverage'],
            'snapshot.coverage' => ['required', Rule::in([self::COVERAGE_V2, self::COVERAGE_SERVER])], 'snapshot.sources' => ['present', 'array', 'max:32']])->validate();
        $legacy = $input;
        $legacy['schema_version'] = 1;
        $legacy['coverage'] = self::COVERAGE;
        $legacy['sources'] = [];
        $extras = [];
        $planIds = $costIds = $buildingValues = $contractorValues = $taskIds = $requestIds = [];
        $planCount = $costCount = 0;
        $financial = in_array($input['persona'] ?? null, ['demo_manager', 'executive', 'manager'], true);
        foreach ($input['sources'] as $source) {
            $project = ($source['kind'] ?? null) === 'project';
            $keys = $project ? 'ref,kind,area_id,title,version,status,tasks,buildings,contractor_plans,costs,comparisons'
                : 'ref,kind,area_id,title,version,status,priority,due_on,building,building_id,building_version,project_id,task_id';
            $uuid = ['required', 'uuid', 'lowercase'];
            $label = ['required', 'string', 'max:100', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u'];
            $rules = ['source' => ['required', 'array:'.$keys], 'source.ref' => ['required', 'string'],
                'source.area_id' => ['required', 'uuid', 'lowercase']];
            if ($project) {
                $rules += ['source.buildings' => ['present', 'array', 'max:8'], 'source.buildings.*' => ['required', 'array:id,title,version'],
                    'source.buildings.*.id' => [...$uuid, 'distinct:strict'], 'source.buildings.*.title' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f]/u'],
                    'source.buildings.*.version' => ['required', 'integer', 'min:1'],
                    'source.tasks' => ['present', 'array', 'max:16'], 'source.tasks.*' => ['required', 'array:id,title,version,status,assigned_person,assigned_person_id,priority,due_on,building_id,request'],
                    'source.tasks.*.assigned_person' => ['present', 'nullable', 'string', 'max:100'],
                    'source.tasks.*.assigned_person_id' => ['present', 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/D'],
                    'source.tasks.*.building_id' => ['present', 'nullable', 'uuid', 'lowercase'],
                    'source.tasks.*.request' => ['present', 'nullable', 'array:id,version,status'],
                    'source.tasks.*.request.id' => ['required_with:source.tasks.*.request', 'uuid', 'lowercase'],
                    'source.tasks.*.request.version' => ['required_with:source.tasks.*.request', 'integer', 'min:1'],
                    'source.tasks.*.request.status' => ['required_with:source.tasks.*.request', 'string'],
                    'source.contractor_plans' => ['present', 'array', 'max:8'], 'source.contractor_plans.*' => ['required', 'array:id,version,contractor_id,contractor_version,contractor_title,starts_on,ends_on'],
                    'source.contractor_plans.*.id' => [...$uuid, 'distinct:strict'], 'source.contractor_plans.*.version' => ['required', 'integer', 'min:1'],
                    'source.contractor_plans.*.contractor_id' => $uuid, 'source.contractor_plans.*.contractor_version' => ['required', 'integer', 'min:1'],
                    'source.contractor_plans.*.contractor_title' => $label,
                    'source.contractor_plans.*.starts_on' => ['required', 'date_format:Y-m-d'],
                    'source.contractor_plans.*.ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:source.contractor_plans.*.starts_on'],
                    'source.costs' => ['present', 'array', 'max:16'], 'source.costs.*' => ['required', 'array:id,version,amount_minor,currency,basis'],
                    'source.costs.*.id' => [...$uuid, 'distinct:strict'], 'source.costs.*.version' => ['required', 'integer', 'min:1'],
                    'source.costs.*.amount_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
                    'source.costs.*.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
                    'source.costs.*.basis' => ['required', Rule::in(['estimate', 'recorded_cost'])],
                    'source.comparisons' => ['present', 'array']];
            } else {
                foreach (['building_id', 'project_id', 'task_id'] as $field) {
                    $rules['source.'.$field] = ['present', 'nullable', 'uuid', 'lowercase'];
                }
                $rules['source.building_version'] = ['present', 'nullable', 'integer', 'min:1'];
            }
            Validator::make(['source' => $source], $rules)->validate();
            $base = array_intersect_key($source, array_flip(['ref', 'kind', 'area_id', 'title', 'version', 'status']));
            if ($project) {
                foreach (['tasks', 'buildings', 'contractor_plans', 'costs', 'comparisons'] as $field) {
                    abort_unless(array_is_list($source[$field]), 422);
                }
                abort_unless($financial || $source['costs'] === [], 422);
                $planCount += count($source['contractor_plans']);
                $costCount += count($source['costs']);
                abort_if($planCount > 32 || $costCount > 64, 422);
                $buildings = [];
                foreach ($source['buildings'] as $building) {
                    abort_unless(is_int($building['version']), 422);
                    $value = [$source['area_id'], $building['title'], $building['version']];
                    abort_if(isset($buildingValues[$building['id']]) && $buildingValues[$building['id']] !== $value, 422);
                    $buildingValues[$building['id']] = $value;
                    $buildings[] = ['id' => $building['id'], 'title' => $building['title'], 'version' => $building['version']];
                }
                $tasks = [];
                foreach ($source['tasks'] as $task) {
                    abort_unless(is_string($task['id'] ?? null) && ! isset($taskIds[$task['id']]), 422);
                    $taskIds[$task['id']] = true;
                    abort_unless(($task['assigned_person'] === null) === ($task['assigned_person_id'] === null)
                        && ($task['building_id'] === null || in_array($task['building_id'], array_column($buildings, 'id'), true)), 422);
                    if ($task['request'] !== null) {
                        $requestKeys = array_keys($task['request']);
                        sort($requestKeys, SORT_STRING);
                        abort_unless($requestKeys === ['id', 'status', 'version'], 422);
                        abort_unless(! isset($requestIds[$task['request']['id']]), 422);
                        $requestIds[$task['request']['id']] = true;
                        abort_unless(is_int($task['request']['version']) && in_array($task['request']['status'], ['submitted', 'triaged', 'assigned', 'in_progress', 'completed', 'closed', 'not_recorded'], true), 422);
                    }
                    $tasks[] = array_intersect_key($task, array_flip(['id', 'title', 'version', 'status', 'assigned_person', 'priority', 'due_on']));
                }
                $plans = [];
                foreach ($source['contractor_plans'] as $plan) {
                    abort_unless(is_int($plan['version']) && is_int($plan['contractor_version']) && ! isset($planIds[$plan['id']]), 422);
                    $planIds[$plan['id']] = true;
                    $contractorValue = [$source['area_id'], $plan['contractor_version'], $plan['contractor_title']];
                    abort_if(isset($contractorValues[$plan['contractor_id']]) && $contractorValues[$plan['contractor_id']] !== $contractorValue, 422);
                    $contractorValues[$plan['contractor_id']] = $contractorValue;
                    $plans[] = ['id' => $plan['id'], 'version' => $plan['version'], 'contractor_id' => $plan['contractor_id'],
                        'contractor_version' => $plan['contractor_version'], 'contractor_title' => $plan['contractor_title'],
                        'starts_on' => $plan['starts_on'], 'ends_on' => $plan['ends_on']];
                }
                $costs = [];
                foreach ($source['costs'] as $cost) {
                    abort_unless(is_int($cost['version']) && is_int($cost['amount_minor']) && ! isset($costIds[$cost['id']]), 422);
                    $costIds[$cost['id']] = true;
                    $costs[] = ['id' => $cost['id'], 'version' => $cost['version'], 'amount_minor' => $cost['amount_minor'], 'currency' => $cost['currency'], 'basis' => $cost['basis']];
                }
                $comparisons = ReviewerAiEvidence::comparisons($costs);
                abort_unless($source['comparisons'] === $comparisons, 422, 'Review the exact fictional cost inputs and calculations again.');
                $legacy['sources'][] = $base + ['tasks' => $tasks, 'buildings' => array_column($buildings, 'title')];
                $extras[$source['ref']] = ['buildings' => $buildings, 'tasks' => $source['tasks'], 'contractor_plans' => $plans, 'costs' => $costs, 'comparisons' => $comparisons];
            } else {
                abort_unless(($source['building_id'] === null) === ($source['building_version'] === null)
                    && ($source['building_version'] === null || is_int($source['building_version']))
                    && ($source['task_id'] === null || $source['project_id'] !== null), 422);
                $legacy['sources'][] = $base + array_intersect_key($source, array_flip(['priority', 'due_on', 'building']));
                $extras[$source['ref']] = array_intersect_key($source, array_flip(['building_id', 'building_version', 'project_id', 'task_id']));
            }
        }
        $normalized = $this->normalize($legacy);
        foreach ($normalized['sources'] as &$source) {
            $extra = $extras[$source['ref']];
            if ($source['kind'] === 'project') {
                foreach ($source['tasks'] as $i => &$task) {
                    $raw = $extra['tasks'][$i];
                    $task += ['assigned_person_id' => $raw['assigned_person_id'], 'building_id' => $raw['building_id'],
                        'request' => $raw['request'] === null ? null : ['id' => $raw['request']['id'], 'version' => $raw['request']['version'], 'status' => $raw['request']['status']]];
                }
                unset($task);
                $source['buildings'] = $extra['buildings'];
                $source += array_intersect_key($extra, array_flip(['contractor_plans', 'costs', 'comparisons']));
            } else {
                $source += $extra;
                if ($source['project_id'] !== null) {
                    $project = collect($normalized['sources'])->firstWhere('ref', 'project:'.$source['project_id']);
                    abort_unless($project !== null && $project['area_id'] === $source['area_id']
                        && ($source['task_id'] === null || in_array($source['task_id'], array_column($project['tasks'], 'id'), true)), 422);
                }
            }
        }
        unset($source);
        foreach ($normalized['sources'] as $source) {
            abort_if($source['kind'] === 'request' && isset($requestIds[substr($source['ref'], 8)]), 422);
        }
        $normalized['schema_version'] = 2;
        $normalized['coverage'] = $input['coverage'];

        return $normalized;
    }
}
