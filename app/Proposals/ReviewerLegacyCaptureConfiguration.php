<?php

namespace App\Proposals;

/** Nonsecret restrictive config only. No application, clock authority, DB, source/storage read or activation. */
final class ReviewerLegacyCaptureConfiguration
{
    public const MAX_JSON_BYTES = 4096;

    private const FIELDS = ['tenant_id', 'client_id', 'silo_id', 'storage_id', 'version_hash', 'manifest_sha256', 'source_hash', 'build_id', 'module_sha256', 'expires_at'];

    private const OWNER_FIELDS = ['purpose', 'owner_workspaces', 'owner_client_id', 'owner_silo_id', 'runtime_client_id', 'runtime_silo_id', 'runtime_storage_id'];

    public static function parse(mixed $text): ?array
    {
        if ($text === '') {
            return [];
        }
        if (! is_string($text) || strlen($text) > self::MAX_JSON_BYTES) {
            return null;
        }
        try {
            $object = json_decode($text, false, 4, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass || count(get_object_vars($object)) > 1 || self::duplicateKeys($text)) {
                return null;
            }
            $rows = [];
            foreach (get_object_vars($object) as $version => $row) {
                if (! self::uuid($version) || ! $row instanceof \stdClass || ! self::keys(get_object_vars($row), self::FIELDS)) {
                    return null;
                }
                $row = get_object_vars($row);
                if (! is_int($row['tenant_id']) || $row['tenant_id'] < 1) {
                    return null;
                }
                foreach (['client_id', 'silo_id', 'storage_id'] as $key) {
                    if (! self::uuid($row[$key])) {
                        return null;
                    }
                }
                foreach (['version_hash', 'manifest_sha256', 'source_hash', 'module_sha256'] as $key) {
                    if (! is_string($row[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', $row[$key])) {
                        return null;
                    }
                }
                if (! is_string($row['build_id']) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $row['build_id'])
                    || ! self::utc($row['expires_at'])) {
                    return null;
                }
                $rows[$version] = $row;
            }

            return $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Only fixed server-owned values from the Enterprise overlay may call this; this establishes no runtime/identity grant. */
    public static function configuration(mixed $flag, mixed $text, array $ownerContext): array
    {
        $closed = ['enabled' => false, 'approvals' => null];
        if (! self::keys($ownerContext, self::OWNER_FIELDS)) {
            return $closed;
        }
        if ($ownerContext['purpose'] !== 'proposal_owner') {
            return ['enabled' => false, 'approvals' => []];
        }
        foreach (['owner_client_id', 'owner_silo_id', 'runtime_client_id', 'runtime_silo_id', 'runtime_storage_id'] as $key) {
            if (! self::uuid($ownerContext[$key])) {
                return $closed;
            }
        }
        $workspaces = $ownerContext['owner_workspaces'];
        if (! is_array($workspaces) || count($workspaces) !== 1 || ! is_int(array_key_first($workspaces))
            || array_key_first($workspaces) < 1 || array_values($workspaces) !== ['enterprise']
            || $ownerContext['owner_client_id'] !== $ownerContext['runtime_client_id']
            || $ownerContext['owner_silo_id'] !== $ownerContext['runtime_silo_id']) {
            return $closed;
        }
        $map = self::parse($text);
        if ($map === null) {
            return $closed;
        }
        if ($map === []) {
            return ['enabled' => false, 'approvals' => []];
        }
        $row = array_values($map)[0];
        if ($row['tenant_id'] !== array_key_first($workspaces) || $row['client_id'] !== $ownerContext['runtime_client_id']
            || $row['silo_id'] !== $ownerContext['runtime_silo_id'] || $row['storage_id'] !== $ownerContext['runtime_storage_id']) {
            return $closed;
        }

        return ['enabled' => $flag === true, 'approvals' => $map];
    }

    private static function keys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }

    private static function utc(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /** JSON syntax is already validated; tokenize quoted strings atomically so punctuation inside values is inert. */
    private static function duplicateKeys(string $text): bool
    {
        $count = preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{},:]/s', $text, $tokens);
        if ($count === false) {
            return true;
        }
        $stack = [];
        foreach ($tokens[0] as $token) {
            if ($token === '{') {
                $stack[] = ['key' => true, 'seen' => []];
            } elseif ($token === '}') {
                array_pop($stack);
            } elseif ($stack !== []) {
                $top = count($stack) - 1;
                if ($token === ',') {
                    $stack[$top]['key'] = true;
                } elseif ($token[0] === '"' && $stack[$top]['key']) {
                    $key = json_decode($token, true, 4, JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $stack[$top]['seen'])) {
                        return true;
                    }
                    $stack[$top]['seen'][$key] = true;
                    $stack[$top]['key'] = false;
                }
            }
        }

        return false;
    }
}
