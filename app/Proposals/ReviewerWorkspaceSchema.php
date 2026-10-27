<?php

namespace App\Proposals;

use Illuminate\Support\Str;

/** Closed fictional graph and policy validation only; no retrieval, actor or ledger authority. */
final class ReviewerWorkspaceSchema
{
    public const PERSONAS = ['demo_manager', 'executive', 'manager', 'worker', 'requester'];

    public const PROJECT_STATUSES = ['active', 'on_track', 'on_hold', 'at_risk', 'archived'];

    public const TASK_STATUSES = ['active', 'in_progress', 'interrupted', 'awaiting_verification', 'completed', 'closed', 'archived'];

    public const TICKET_STATUSES = ['submitted', 'triaged', 'assigned', 'in_progress', 'completed', 'closed'];

    public const TYPES = ['general', 'it_support', 'event_setup', 'plumbing', 'lighting', 'maintenance'];

    public static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        abort_unless($actual === $keys, 422, 'Only the closed fictional fields are accepted.');
    }

    public static function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    public static function digest(array $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    /** The closed command payload is always a JSON object, including plan.cancel's {}. */
    public static function commandCanonical(array $command): string
    {
        if (($command['payload'] ?? null) === []) {
            $command['payload'] = new \stdClass;
        }

        return self::canonical($command);
    }

    public static function commandDigest(array $command): string
    {
        return hash('sha256', self::commandCanonical($command));
    }

    public static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function uuid(mixed $value): bool
    {
        return is_string($value) && $value === strtolower($value) && Str::isUuid($value);
    }

    /** JSON object key order is irrelevant; target types, closed keys and identity remain exact. */
    public static function sameTarget(mixed $left, mixed $right): bool
    {
        foreach ([$left, $right] as $target) {
            if (! is_array($target)) {
                return false;
            }
            $keys = array_keys($target);
            sort($keys, SORT_STRING);
            if ($keys !== ['id', 'kind'] || ! in_array($target['kind'], ['project', 'task', 'ticket'], true)
                || ! self::uuid($target['id'])) {
                return false;
            }
        }

        return $left['id'] === $right['id'] && $left['kind'] === $right['kind'];
    }

    public static function person(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $value) === 1;
    }

    public static function version(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 9007199254740991;
    }

    public static function text(mixed $value, int $max, bool $empty = false): bool
    {
        return is_string($value) && mb_strlen($value) <= $max && ($empty || trim($value) !== '')
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value) === 0;
    }

    public static function date(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function utc(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    public static function uniqueStrings(array $value): bool
    {
        return count(array_filter($value, 'is_string')) === count($value) && count(array_unique($value)) === count($value);
    }

    public static function timestamp(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d{1,6})?Z$/D', $value, $matches)) {
            return false;
        }

        return self::utc($matches[1].'Z');
    }

    public function policy(array $policy): array
    {
        self::exact($policy, ['schema_version', 'max_role', 'area_ids', 'personas', 'allow_financial']);
        abort_unless($policy['schema_version'] === 1 && in_array($policy['max_role'], ['viewer', 'editor'], true)
            && is_bool($policy['allow_financial']) && is_array($policy['area_ids']) && array_is_list($policy['area_ids'])
            && count($policy['area_ids']) >= 1 && count($policy['area_ids']) <= 8
            && self::uniqueStrings($policy['area_ids'])
            && is_array($policy['personas']) && array_is_list($policy['personas']) && $policy['personas'] !== []
            && self::uniqueStrings($policy['personas'])
            && array_diff($policy['personas'], self::PERSONAS) === [], 422);
        foreach ($policy['area_ids'] as $id) {
            abort_unless(self::uuid($id), 422);
        }
        sort($policy['area_ids'], SORT_STRING);
        sort($policy['personas'], SORT_STRING);

        return $policy;
    }

    public function descriptor(array $descriptor): array
    {
        self::exact($descriptor, ['schema_version', 'workspace_id', 'seed_sha256', 'policy_sha256', 'expires_at']);
        abort_unless($descriptor['schema_version'] === 1 && self::uuid($descriptor['workspace_id'])
            && self::hash($descriptor['seed_sha256']) && self::hash($descriptor['policy_sha256']) && self::utc($descriptor['expires_at']), 422);

        return $descriptor;
    }

    public function graph(array $graph, bool $seed = false): array
    {
        self::exact($graph, ['schema_version', 'areas', 'buildings', 'people', 'projects', 'tasks', 'tickets', 'contractors', 'plans', 'costs']);
        abort_unless($graph['schema_version'] === 1 && strlen(self::canonical($graph)) <= 262144, 422, 'The fictional workspace exceeds its reviewed bound.');
        $caps = ['areas' => 8, 'buildings' => 100, 'people' => 50, 'projects' => 100, 'tasks' => 200, 'tickets' => 200, 'contractors' => 50, 'plans' => 100, 'costs' => 100];
        $keys = [
            'areas' => ['id', 'name', 'visibility'],
            'buildings' => ['id', 'area_id', 'title', 'version', 'status'],
            'people' => ['id', 'name', 'version', 'area_ids', 'active'],
            'projects' => ['id', 'area_id', 'title', 'description', 'version', 'status', 'owner_id', 'due_on'],
            'tasks' => ['id', 'project_id', 'area_id', 'title', 'description', 'version', 'status', 'priority', 'due_on', 'assignee_id', 'building_id'],
            'tickets' => ['id', 'area_id', 'title', 'description', 'version', 'status', 'priority', 'due_on', 'building_id', 'project_id', 'task_id', 'owner_capability_hash', 'position', 'type'],
            'contractors' => ['id', 'area_id', 'title', 'version', 'status'],
            'plans' => ['id', 'area_id', 'project_id', 'contractor_id', 'version', 'status', 'starts_on', 'ends_on'],
            'costs' => ['id', 'area_id', 'project_id', 'version', 'amount_minor', 'currency', 'basis'],
        ];
        $maps = [];
        foreach ($caps as $kind => $cap) {
            abort_unless(is_array($graph[$kind]) && array_is_list($graph[$kind]) && count($graph[$kind]) <= $cap, 422);
            $maps[$kind] = [];
            foreach ($graph[$kind] as $row) {
                abort_unless(is_array($row), 422);
                $rowKeys = isset($row['version']) ? [...$keys[$kind], 'updated_at'] : $keys[$kind];
                if ($kind === 'buildings' && array_key_exists('map_reference', $row)) {
                    $rowKeys[] = 'map_reference';
                    try {
                        ReviewerWorkspaceMapReference::validate($row['map_reference']);
                    } catch (\InvalidArgumentException) {
                        abort(422, 'Official building map reference is unavailable.');
                    }
                }
                self::exact($row, $rowKeys);
                abort_unless(($kind === 'people' ? self::person($row['id']) : self::uuid($row['id'])) && ! isset($maps[$kind][$row['id']]), 422);
                if (array_key_exists('version', $row)) {
                    abort_unless(self::version($row['version']) && ($row['updated_at'] === null || self::timestamp($row['updated_at'])), 422);
                }
                if (array_key_exists('title', $row)) {
                    abort_unless(self::text($row['title'], $kind === 'buildings' ? 200 : 100), 422);
                }
                if (array_key_exists('description', $row)) {
                    abort_unless(self::text($row['description'], 5000, true), 422);
                }
                if (array_key_exists('due_on', $row)) {
                    abort_unless(self::date($row['due_on']), 422);
                }
                if (array_key_exists('priority', $row)) {
                    abort_unless($row['priority'] === null || in_array($row['priority'], ['low', 'normal', 'high', 'urgent'], true), 422);
                }
                $maps[$kind][$row['id']] = $row;
            }
            usort($graph[$kind], fn ($a, $b) => strcmp($a['id'], $b['id']));
        }
        abort_unless($graph['areas'] !== [] && count($graph['people']) + count($graph['contractors']) <= 50
            && count($graph['plans']) + count($graph['costs']) <= 100, 422);
        foreach ($graph['areas'] as $row) {
            abort_unless(self::text($row['name'], 100) && in_array($row['visibility'], ['shared', 'department_only'], true), 422);
        }
        foreach ($graph['people'] as $row) {
            abort_unless(self::text($row['name'], 100) && is_bool($row['active']) && is_array($row['area_ids']) && array_is_list($row['area_ids'])
                && $row['area_ids'] !== [] && count($row['area_ids']) <= 8 && self::uniqueStrings($row['area_ids']), 422);
            foreach ($row['area_ids'] as $area) {
                abort_unless(is_string($area) && isset($maps['areas'][$area]), 422);
            }
        }
        $person = fn ($id, $area) => $id === null || self::person($id) && isset($maps['people'][$id])
            && $maps['people'][$id]['active'] && in_array($area, $maps['people'][$id]['area_ids'], true);
        $building = fn ($id, $area) => $id === null || self::uuid($id) && isset($maps['buildings'][$id]) && $maps['buildings'][$id]['area_id'] === $area;
        foreach (['buildings', 'projects', 'tasks', 'tickets', 'contractors', 'plans', 'costs'] as $kind) {
            foreach ($graph[$kind] as $row) {
                abort_unless(self::uuid($row['area_id']) && isset($maps['areas'][$row['area_id']]), 422);
                if (in_array($kind, ['tasks', 'plans', 'costs'], true)) {
                    abort_unless(self::uuid($row['project_id']) && isset($maps['projects'][$row['project_id']]) && $maps['projects'][$row['project_id']]['area_id'] === $row['area_id'], 422);
                }
                if ($kind === 'buildings' || $kind === 'contractors') {
                    abort_unless(in_array($row['status'], ['active', 'inactive'], true), 422);
                }
                if ($kind === 'projects') {
                    abort_unless(in_array($row['status'], self::PROJECT_STATUSES, true) && $person($row['owner_id'], $row['area_id']), 422);
                }
                if ($kind === 'tasks') {
                    abort_unless(in_array($row['status'], self::TASK_STATUSES, true) && $person($row['assignee_id'], $row['area_id']) && $building($row['building_id'], $row['area_id']), 422);
                }
                if ($kind === 'tickets') {
                    abort_unless(in_array($row['status'], self::TICKET_STATUSES, true) && in_array($row['type'], self::TYPES, true)
                        && is_int($row['position']) && $row['position'] >= 0 && $row['position'] <= 1000000 && $building($row['building_id'], $row['area_id'])
                        && ($row['owner_capability_hash'] === null || ! $seed && self::hash($row['owner_capability_hash'])), 422);
                    if ($row['project_id'] !== null) {
                        abort_unless(self::uuid($row['project_id']) && isset($maps['projects'][$row['project_id']]) && $maps['projects'][$row['project_id']]['area_id'] === $row['area_id'], 422);
                    }
                    if ($row['task_id'] !== null) {
                        abort_unless(self::uuid($row['task_id']) && isset($maps['tasks'][$row['task_id']]) && $maps['tasks'][$row['task_id']]['project_id'] === $row['project_id'] && $maps['tasks'][$row['task_id']]['area_id'] === $row['area_id'], 422);
                    }
                }
                if ($kind === 'plans') {
                    abort_unless(self::uuid($row['contractor_id']) && isset($maps['contractors'][$row['contractor_id']])
                        && $maps['contractors'][$row['contractor_id']]['area_id'] === $row['area_id']
                        && in_array($row['status'], ['planned', 'cancelled'], true) && $row['starts_on'] !== null && $row['ends_on'] !== null
                        && self::date($row['starts_on']) && self::date($row['ends_on']) && $row['starts_on'] <= $row['ends_on'], 422);
                }
                if ($kind === 'costs') {
                    abort_unless(is_int($row['amount_minor']) && $row['amount_minor'] >= 0 && $row['amount_minor'] <= 1000000000
                        && is_string($row['currency']) && preg_match('/^[A-Z]{3}$/D', $row['currency']) && in_array($row['basis'], ['estimate', 'recorded_cost'], true), 422);
                }
            }
        }

        return $graph;
    }
}
