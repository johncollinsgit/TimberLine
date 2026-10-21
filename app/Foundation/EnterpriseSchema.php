<?php

namespace App\Foundation;

use Illuminate\Database\Migrations\Migration;
use RuntimeException;

/** Versioned allowlist of shared, schema-only migrations. Never glob the retail tree. */
final class EnterpriseSchema
{
    /** @return list<Migration> */
    public static function sharedVersionOne(): array
    {
        $manifest = require base_path('apps/enterprise/database/shared-schema-v1.php');
        $migrations = [];
        foreach ($manifest as $filename => $sha256) {
            $path = base_path('database/migrations/'.$filename);
            $source = file_get_contents($path);
            if ($source === false || ! hash_equals($sha256, hash('sha256', str_replace("\r\n", "\n", $source)))) {
                throw new RuntimeException('Enterprise shared schema source changed: '.$filename);
            }
            $migration = require $path;
            if (! $migration instanceof Migration) {
                throw new RuntimeException('Invalid Enterprise shared migration: '.$filename);
            }
            $migrations[] = $migration;
        }

        return $migrations;
    }
}
