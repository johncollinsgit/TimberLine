<?php

namespace App\Console\Commands;

use App\Models\MappingException;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Database\Seeders\CatalogSeeder;

class CatalogReset extends Command
{
    protected $signature = 'catalog:reset {--force}';
    protected $description = 'Reset scents and sizes to canonical data (DEV only unless --force).';

    public function handle(): int
    {
        $isLocal = App::environment('local');
        $force = (bool) $this->option('force');

        if (!$isLocal && !$force) {
            $this->error('Refusing to reset catalog outside local without --force.');
            return self::FAILURE;
        }

        if (!$this->confirm('This will clear scents/sizes and reset mappings. Continue?')) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        DB::transaction(function () {
            if (Schema::hasTable('mapping_exceptions')) {
                MappingException::query()->delete();
            }

            if (Schema::hasTable('order_lines')) {
                $updates = [];
                if (Schema::hasColumn('order_lines', 'scent_id')) {
                    $updates['scent_id'] = null;
                }
                if (Schema::hasColumn('order_lines', 'size_id')) {
                    $updates['size_id'] = null;
                }
                if (Schema::hasColumn('order_lines', 'scent_name')) {
                    $updates['scent_name'] = null;
                }
                if (Schema::hasColumn('order_lines', 'size_code')) {
                    $updates['size_code'] = null;
                }
                if (!empty($updates)) {
                    DB::table('order_lines')->update($updates);
                }
            }

            Scent::query()->delete();
            Size::query()->delete();
        });

        $this->call(CatalogSeeder::class);

        $this->info('Catalog reset complete.');
        return self::SUCCESS;
    }
}
