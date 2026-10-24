<?php

namespace App\Proposals;

/** Nonsecret reviewed metadata only. Parsing never provisions storage or enables a gate. */
final class ReviewerWorkspaceRuntimeConfiguration
{
    public static function parse(mixed $text): ?array
    {
        if (! is_string($text) || $text === '' || strlen($text) > 24000) {
            return null;
        }
        try {
            $value = json_decode($text, true, 12, JSON_THROW_ON_ERROR);
            if (! self::keys($value, ['schema_version', 'anchor_area_id', 'anchor_marker_sha256', 'policies', 'collaboration_policies'])
                || $value['schema_version'] !== 1 || ! ReviewerWorkspaceSchema::uuid($value['anchor_area_id'])
                || ! ReviewerWorkspaceSchema::hash($value['anchor_marker_sha256'])) {
                return null;
            }
            foreach (['policies', 'collaboration_policies'] as $group) {
                $rows = $value[$group];
                if (! is_array($rows) || ($rows !== [] && array_is_list($rows)) || count($rows) > 10) {
                    return null;
                }
                foreach ($rows as $hash => $policy) {
                    if (! ReviewerWorkspaceSchema::hash($hash) || ! is_array($policy)
                        || ! hash_equals($hash, ReviewerWorkspaceSchema::digest($policy))) {
                        return null;
                    }
                    if ($group === 'policies' ? ! self::workspacePolicy($policy) : ! self::commentsPolicy($policy)) {
                        return null;
                    }
                }
            }

            return $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function keys(mixed $value, array $expected): bool
    {
        if (! is_array($value)) {
            return false;
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private static function strings(mixed $rows, array $permitted, int $min, int $max): bool
    {
        return is_array($rows) && array_is_list($rows) && count($rows) >= $min && count($rows) <= $max
            && ReviewerWorkspaceSchema::uniqueStrings($rows) && array_diff($rows, $permitted) === [];
    }

    private static function workspacePolicy(array $policy): bool
    {
        if (! self::keys($policy, ['schema_version', 'max_role', 'area_ids', 'personas', 'allow_financial'])
            || $policy['schema_version'] !== 1 || ! in_array($policy['max_role'], ['viewer', 'editor'], true)
            || ! is_bool($policy['allow_financial']) || ! is_array($policy['area_ids']) || ! array_is_list($policy['area_ids'])
            || count($policy['area_ids']) < 1 || count($policy['area_ids']) > 8 || ! ReviewerWorkspaceSchema::uniqueStrings($policy['area_ids'])
            || ! self::strings($policy['personas'], ReviewerWorkspaceSchema::PERSONAS, 1, 5)) {
            return false;
        }
        foreach ($policy['area_ids'] as $id) {
            if (! ReviewerWorkspaceSchema::uuid($id)) {
                return false;
            }
        }
        // Access canonicalizes these lists before matching the approved policy hash.
        $areas = $policy['area_ids'];
        $personas = $policy['personas'];
        sort($areas, SORT_STRING);
        sort($personas, SORT_STRING);

        return $areas === $policy['area_ids'] && $personas === $policy['personas'];
    }

    private static function commentsPolicy(array $policy): bool
    {
        return self::keys($policy, ['schema_version', 'comments_enabled', 'uploads_enabled', 'write_personas'])
            && $policy['schema_version'] === 1 && $policy['comments_enabled'] === true && $policy['uploads_enabled'] === false
            && self::strings($policy['write_personas'], ['demo_manager', 'manager', 'worker', 'requester'], 0, 4);
    }
}
