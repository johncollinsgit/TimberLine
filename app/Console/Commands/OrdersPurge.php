<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OrdersPurge extends Command
{
    protected $signature = 'orders:purge {--force : Skip confirmation prompt}';

    protected $description = 'Delete all orders, order lines, and mapping exceptions (non-destructive to users).';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete ALL orders, order lines, and mapping exceptions. Continue?')) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        DB::transaction(function () {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::table('order_lines')->delete();
            DB::table('mapping_exceptions')->delete();
            DB::table('orders')->delete();
            DB::statement('PRAGMA foreign_keys = ON');
        });

        $this->info('Orders, order lines, and mapping exceptions have been deleted.');

        return self::SUCCESS;
    }
}
