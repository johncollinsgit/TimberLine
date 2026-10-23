<?php

namespace App\Enterprise\Provisioning;

use RuntimeException;

/** Selected only by the trusted process environment, before Laravel reads .env. */
final class RuntimePaths
{
    public static function resolve(string $default): array
    {
        $configured = getenv('ENTERPRISE_RUNTIME_ROOT');
        if ($configured === false || $configured === '') {
            return ['root' => $default, 'selected' => false];
        }
        if (! preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $configured)) {
            throw new RuntimeException('Enterprise runtime root must be an existing absolute private directory.');
        }
        $root = realpath($configured);
        if ($root === false || ! is_dir($root) || realpath($default) === $root) {
            throw new RuntimeException('Enterprise client runtime root is invalid.');
        }
        $repository = str_replace('\\', '/', (string) realpath(dirname($default, 2)));
        $normalized = str_replace('\\', '/', $root);
        if (strtolower($normalized) === strtolower($repository) || str_starts_with(strtolower($normalized), strtolower($repository).'/')) {
            throw new RuntimeException('Client state must be outside the release source tree.');
        }
        foreach (['storage', 'bootstrap/cache'] as $directory) {
            if (! is_dir($root.'/'.$directory) || str_replace('\\', '/', (string) realpath($root.'/'.$directory)) !== $normalized.'/'.$directory) {
                throw new RuntimeException('Enterprise client runtime directories must be prepared explicitly.');
            }
        }

        return ['root' => $root, 'selected' => true];
    }
}
