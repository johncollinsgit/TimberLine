<?php

namespace App\Proposals;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Only explicit reviewer-supplied fictional fields. This never reads or establishes native campus authority. */
final class ReviewerAiSnapshot
{
    public const COVERAGE = 'Reviewer-supplied fictional browser snapshot. Selected project tasks and tickets only; no native campus records, notes, files, review comments, contact details, mailbox or financial records. Missing/bounded fields do not prove absence.';

    public function normalize(array $input): array
    {
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
}
