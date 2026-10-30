<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Read-only selection and arithmetic over the current, role-scoped ticket snapshot. */
class JobLookup
{
    public const STATUSES = ['Pending approval', 'Needs details', 'Approved', 'Assigned', 'In progress', 'Awaiting parts', 'Completed', 'Rejected'];

    public function buildingName(string $name): string
    {
        $normalize = fn ($value) => mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $value));
        foreach (json_decode(file_get_contents(resource_path('data/campus-facilities/building-names.json')), true, 32, JSON_THROW_ON_ERROR) as $building) {
            if (in_array($normalize($name), [$normalize($building['name']), $normalize($building['referenceName'])], true)) {
                return $building['name'];
            }
        }

        return $name;
    }

    public function schema(array $context): array
    {
        $buildings = array_values(array_unique([...array_column($context['campus']['buildings'], 'name'), ...array_column($context['jobs'], 'building')]));

        return ['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['summary', 'details']],
            'building' => ['type' => 'string', 'enum' => ['', ...$buildings]],
            'team' => ['type' => 'string', 'enum' => ['', ...Tickets::TEAMS]],
            'statuses' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [...self::STATUSES, 'Open', 'Closed']]],
            'assignee' => ['type' => 'string', 'enum' => ['', 'unassigned', 'sam', 'casey', 'riley']],
            'priority' => ['type' => 'string', 'enum' => ['', 'Low', 'Normal', 'High', 'Urgent']],
            'search' => ['type' => 'string', 'description' => 'A literal issue/room keyword only when requested. Empty for a whole-building or workload summary.'],
            'groupBy' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['status', 'team', 'building']]],
            'ticketIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only for details about explicitly requested ticket IDs. Ignored for summaries.'],
        ], 'required' => ['kind', 'building', 'team', 'statuses', 'assignee', 'priority', 'search', 'groupBy', 'ticketIds'], 'additionalProperties' => false];
    }

    public function lookup(array $input): array
    {
        Validator::make($input, ['filters' => 'required|array'])->validate();

        return $this->render(app(CampusAssistant::class)->context($input), $input['filters']);
    }

    public function render(array $context, array $filters): array
    {
        foreach (['statuses', 'groupBy', 'ticketIds'] as $key) {
            abort_unless(is_array($filters[$key] ?? null), 422, 'Job filter lists must be arrays.');
        }
        $filters = array_map(fn ($v) => $v === null ? '' : $v, $filters);
        abort_if(array_diff(array_keys($filters), array_keys($this->schema($context)['properties'])), 422, 'Unsupported job filters.');
        Validator::make($filters, [
            'kind' => ['required', Rule::in(['summary', 'details'])], 'building' => ['present', 'string', 'max:3000'],
            'team' => ['present', Rule::in(['', ...Tickets::TEAMS])], 'statuses' => ['present', 'array', 'max:10'],
            'statuses.*' => [Rule::in([...self::STATUSES, 'Open', 'Closed'])],
            'assignee' => ['present', Rule::in(['', 'unassigned', 'sam', 'casey', 'riley'])],
            'priority' => ['present', Rule::in(['', 'Low', 'Normal', 'High', 'Urgent'])], 'search' => ['present', 'string', 'max:120'],
            'groupBy' => ['present', 'array', 'max:3'], 'groupBy.*' => [Rule::in(['status', 'team', 'building'])],
            'ticketIds' => ['present', 'array', 'max:8'], 'ticketIds.*' => ['string', 'max:50'],
        ])->validate();
        // A nonempty unknown building never silently widens to the whole campus.
        $known = array_unique([...array_column($context['campus']['buildings'], 'name'), ...array_column($context['jobs'], 'building')]);
        abort_if($filters['building'] !== '' && ! in_array($filters['building'], $known, true), 422, 'Choose a known campus building.');
        $filters['building'] = $this->buildingName($filters['building']);
        $closed = ['Completed', 'Rejected'];
        $jobs = array_values(array_filter($context['jobs'], function ($job) use ($filters, $closed) {
            $isClosed = in_array($job['status'], $closed, true);

            return ($filters['kind'] !== 'details' || in_array($job['id'], $filters['ticketIds'], true))
                && ($filters['building'] === '' || $job['building'] === $filters['building'])
                && ($filters['team'] === '' || $job['team'] === $filters['team'])
                && (! $filters['statuses'] || in_array($job['status'], $filters['statuses'], true) || ($isClosed && in_array('Closed', $filters['statuses'], true)) || (! $isClosed && in_array('Open', $filters['statuses'], true)))
                && ($filters['assignee'] === '' || ($job['assignee'] ?: 'unassigned') === $filters['assignee'])
                && ($filters['priority'] === '' || $job['priority'] === $filters['priority'])
                && ($filters['search'] === '' || mb_stripos($job['title'].' '.$job['area'], $filters['search']) !== false);
        }));
        $total = count($jobs);
        $closedCount = count(array_filter($jobs, fn ($j) => in_array($j['status'], $closed, true)));
        $counts = ['total' => $total, 'open' => $total - $closedCount, 'closed' => $closedCount,
            'status' => array_count_values(array_column($jobs, 'status')), 'team' => array_count_values(array_column($jobs, 'team')), 'building' => array_count_values(array_column($jobs, 'building'))];
        $scope = $filters['building'] !== '' ? Str::limit($filters['building'], 100) : 'This view';
        $answer = $scope.': '.$total.' matching '.($total === 1 ? 'job' : 'jobs').' — '.$counts['open'].' open, '.$closedCount.' closed.';
        if ($total) {
            foreach (array_unique($filters['groupBy'] ?: ['status', 'team']) as $group) {
                $parts = [];
                foreach (array_slice($counts[$group], 0, 12, true) as $label => $count) {
                    $parts[] = Str::limit($label, 80).': '.$count;
                }
                $answer .= "\nBy ".$group.': '.implode('; ', $parts).'.'.(count($counts[$group]) > 12 ? ' Showing 12 of '.count($counts[$group]).' groups.' : '');
            }
        } else {
            $answer .= ' No jobs match these filters in your selected demo view.';
        }
        $shown = array_slice($jobs, 0, mb_strlen($answer) > 1800 ? 4 : 8);
        if ($shown) {
            $answer .= "\n\n".($total > count($shown) ? 'Showing '.count($shown).' of '.$total.' matching jobs:' : 'Matching jobs:');
            foreach ($shown as $job) {
                $answer .= "\n".$job['id'].' · '.Str::limit($job['title'], 85).' · '.Str::limit($job['building'], 80).' / '.Str::limit($job['area'], 80).' · '.$job['team'].' · '.$job['status'].' · '.$job['priority'].' priority · '.$job['assignedTo'];
            }
        }

        return ['answer' => $answer, 'ticketIds' => array_column($shown, 'id'), 'counts' => $counts, 'filters' => $filters, 'asOf' => $context['asOf'], 'referenceStale' => $context['campus']['stale'], 'scope' => $context['view']['scope'], 'readOnly' => true];
    }
}
