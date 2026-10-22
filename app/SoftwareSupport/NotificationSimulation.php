<?php

namespace App\SoftwareSupport;

use Illuminate\Support\Facades\DB;

class NotificationSimulation
{
    /** No provider adapters exist here. No destinations or credentials are read. */
    public function run(array $failChannels = []): int
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Simulation is local/testing only.');
        $count = 0;
        foreach (Record::where('kind', 'notification')->orderBy('created_at')->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id, $failChannels): int {
                $record = Record::whereKey($id)->lockForUpdate()->firstOrFail();
                $payload = $record->payload;
                if (! in_array($payload['state'], ['pending_simulation', 'failed_simulation'], true) || $payload['attempts'] >= 3) {
                    return 0;
                }
                // Owner-row lock serializes rate limiting across concurrent workers/channels.
                \App\Models\Tenant::whereKey($record->owner_tenant_id)->lockForUpdate()->firstOrFail();
                $recent = Record::where('owner_tenant_id', $record->owner_tenant_id)->where('kind', 'notification')->where('updated_at', '>=', now()->subHour())->get()
                    ->filter(fn ($entry) => $entry->payload['state'] === 'simulated' && $entry->payload['channel'] === $payload['channel'])->count();
                if ($recent >= max(1, (int) config('software_support.simulation_hourly_limit', 10))) {
                    return 0; // Remains visibly pending, never silently dropped.
                }
                $failed = in_array($payload['channel'], $failChannels, true);
                $record->update(['payload' => [...$payload, 'attempts' => $payload['attempts'] + 1, 'state' => $failed ? 'failed_simulation' : 'simulated', 'error_code' => $failed ? 'fake_provider_failure' : null]]);

                return 1;
            });
        }

        return $count;
    }
}
