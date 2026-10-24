<?php

namespace App\Console\Commands;

use App\Services\Pos\PosReadiness;
use App\Services\Pos\PosStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class PosReadinessCommand extends Command
{
    protected $signature = 'pos:readiness {--tenant=}';

    protected $description = 'Read saved POS readiness without changing records or contacting providers';

    public function handle(PosStore $store, PosReadiness $readiness): int
    {
        $report = ['ready' => false, 'collection_enabled' => config('pos_live.collection_enabled') === true,
            'physical_device' => 'not_observed_by_this_command', 'physical_acceptance_recorded' => false, 'registers' => [], 'blockers' => []];
        try {
            $report['runtime_id'] = $store->runtime();
            if (! Schema::hasTable('pos_live_registers')) {
                $report['blockers'][] = 'pos_schema_missing';
            } elseif (! $this->option('tenant')) {
                $report['blockers'][] = 'explicit_tenant_required';
            } else {
                $tenant = (int) $this->option('tenant');
                foreach ($store->rows('registers', $tenant)->orderBy('id')->get() as $register) {
                    $connection = $store->rows('connections', $tenant)->where('id', $register->connection_id)->first();
                    $accepted = ($connection->provider ?? 'square_native') === 'stripe_terminal' ? $store->rows('readers', $tenant)->where('register_id', $register->id)->first() : $connection;
                    $report['registers'][] = ['register_id' => $register->id, 'name' => $register->name,
                        'provider' => $connection->provider ?? 'square_native', 'reader_id' => $accepted->reader_id ?? null, 'accepted_build' => $accepted->acceptance_build ?? null, 'physically_accepted' => (bool) ($accepted?->acceptance_at), 'location' => $register->location_name, 'blockers' => $connection ? $readiness->blockers($register, $connection) : ['merchant_missing']];
                }
                if (! $report['registers']) {
                    $report['blockers'][] = 'no_provisioned_registers';
                }
            }
        } catch (\App\Services\Pos\PosException $error) {
            $report['blockers'][] = $error->blocker;
        }
        $report['physical_acceptance_recorded'] = count($report['registers']) > 0 && collect($report['registers'])->every(fn ($r) => $r['physically_accepted']);
        $report['configuration_ready'] = ! $report['blockers'] && count($report['registers']) > 0
            && collect($report['registers'])->every(fn ($r) => ! $r['blockers']);
        $report['ready'] = $report['configuration_ready'] && $report['physical_acceptance_recorded'];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
