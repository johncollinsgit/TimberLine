<?php

namespace App\Proposals;

/** One fixed public renderer definition; no runtime, publication, token or private payload. */
final class LegacyCampusV5Definition
{
    public const RENDERER = 'lees-mcrae-v5';

    public const INDEX_SHA256 = 'b71565febc5f6e65e728a149259ea4b41ffcfbd5e48512e76576c6253d27c5fd';

    public const DEFINITION_SHA256 = 'b9a2f4a64908e2a4d608c144297b9bb458378cd69dafd0c9bf08cabb32395de7';

    public static function metadata(): array
    {
        $path = dirname(__DIR__, 2).'/resources/legacy-campus/v5-definition.json';
        $info = lstat($path);
        abort_unless($info && ($info['mode'] & 0170000) === 0100000 && $info['nlink'] === 1 && ! is_link($path) && $info['size'] < 100000, 503, 'The legacy renderer definition is unavailable.');
        $bytes = file_get_contents($path);
        abort_unless(hash_equals(self::DEFINITION_SHA256, hash('sha256', $bytes)), 503, 'The legacy renderer definition changed.');

        return json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    }
}
