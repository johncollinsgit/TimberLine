<?php

namespace App\Foundation;

use Illuminate\Database\Migrations\Migration;

/** Opt-in, schema-only POS composition for an isolated Enterprise runtime. */
final class EnterprisePosSchema
{
    public static function install(): void
    {
        if (config('enterprise.runtime') !== true || config('enterprise.pos_enabled') !== true
            || ! is_string(config('pos_live.runtime_id')) || config('pos_live.runtime_id') === '') {
            throw new \RuntimeException('Enterprise POS requires its explicit isolated runtime and feature configuration.');
        }
        $manifest = require base_path('apps/enterprise/database/pos-schema-v1.php');
        foreach ($manifest as $filename => $sha256) {
            $path = base_path('database/migrations/'.$filename);
            $source = file_get_contents($path);
            if ($source === false || ! hash_equals($sha256, hash('sha256', str_replace("\r\n", "\n", $source)))) {
                throw new \RuntimeException('Enterprise POS schema source changed: '.$filename);
            }
            $migration = require $path;
            if (! $migration instanceof Migration) {
                throw new \RuntimeException('Invalid Enterprise POS schema source.');
            }
            $migration->up();
        }
    }
}
