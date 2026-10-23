<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $filename = '2026_10_22_150000_create_service_proposal_tables.php';
        $manifest = require base_path('apps/enterprise/database/shared-schema-v1.php');
        $path = base_path('database/migrations/'.$filename);
        $source = file_get_contents($path);
        if ($source === false || ! isset($manifest[$filename])
            || ! hash_equals($manifest[$filename], hash('sha256', str_replace("\r\n", "\n", $source)))) {
            throw new RuntimeException('The reviewed proposal schema source changed.');
        }
        (require $path)->up();
    }

    public function down(): void
    {
        // Code rollback must retain private immutable publications, comments and requests.
    }
};
