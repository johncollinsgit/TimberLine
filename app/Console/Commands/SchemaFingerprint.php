<?php

namespace App\Console\Commands;

use App\Services\Operations\MySqlSchemaFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SchemaFingerprint extends Command
{
    protected $signature = 'schema:fingerprint
        {--expect-file=database/schema/mysql-schema.sha256 : A file containing the expected SHA-256 fingerprint}
        {--write= : Write the current fingerprint to this file instead of comparing it}';

    protected $description = 'Compare the active MySQL schema with the approved data-free schema fingerprint.';

    public function handle(MySqlSchemaFingerprint $fingerprint): int
    {
        try {
            $result = $fingerprint->inspect(DB::connection());
        } catch (Throwable $exception) {
            $this->error('Schema fingerprint could not run: '.$exception->getMessage());

            return self::FAILURE;
        }

        $writePath = trim((string) $this->option('write'));
        if ($writePath !== '') {
            $directory = dirname($writePath);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error("Could not create schema fingerprint directory: {$directory}");

                return self::FAILURE;
            }

            file_put_contents($writePath, $result['fingerprint']."\n");
            $this->info("Wrote MySQL schema fingerprint to {$writePath}.");

            return self::SUCCESS;
        }

        $expectedPath = (string) $this->option('expect-file');
        if (! is_file($expectedPath)) {
            $this->error("Approved schema fingerprint is missing: {$expectedPath}");

            return self::FAILURE;
        }

        $expected = trim((string) file_get_contents($expectedPath));
        if (! preg_match('/^[a-f0-9]{64}$/', $expected)) {
            $this->error("Approved schema fingerprint is invalid: {$expectedPath}");

            return self::FAILURE;
        }

        if (! hash_equals($expected, $result['fingerprint'])) {
            $this->error('MySQL schema drift detected. Expected '.$expected.' but found '.$result['fingerprint'].'.');
            $this->line('Do not edit production. Recreate the MySQL baseline in a disposable database, review the SQL diff, then commit both approved baseline files.');

            return self::FAILURE;
        }

        $this->info('MySQL schema matches the approved fingerprint.');

        return self::SUCCESS;
    }
}
