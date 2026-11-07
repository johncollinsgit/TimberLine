<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\PaymentsCore\Contract;
use App\Services\Team\AsanaReadProvider;
use App\Services\Team\Operations\EventTasks;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsProviders;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;

/** Choose among already authorized registers, using current assignments to reviewed events. */
final class PosOperatorContext
{
    public function __construct(private PosStore $pos, private StockLedger $stock, private AsanaReadProvider $asana) {}

    public function resolve(array $actor, array $registers): array
    {
        $matches = [];
        $issues = [];
        foreach ($registers as $register) {
            try {
                $settings = $this->pos->decrypt($register->configuration);
                $pool = $this->stock->rows('pools', $actor['tenant_id'])->where('id', $settings['inventory_pool_id'] ?? '')
                    ->where('active', true)->where('kind', 'show')->first();
                if (! $pool || ! preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $event)) {
                    continue;
                }
                $binding = $this->stock->rows('bindings', $actor['tenant_id'])->where('channel', 'pos')
                    ->where('channel_key', $this->pos->runtime().':'.$register->id)->where('active', true)->first();
                if (! $binding || $binding->pool_id !== $pool->id) {
                    continue;
                }
                foreach ($this->stock->rows('event_links', $actor['tenant_id'])->where('event_id', (int) $event[1])
                    ->whereNotNull('reviewed_by')->whereIn('provider', ['asana', 'teams'])->get() as $link) {
                    $metadata = json_decode($link->metadata, true, 64, JSON_THROW_ON_ERROR);
                    $today = now()->timezone($metadata['timezone'] ?? ($register->timezone ?? config('app.timezone')))->toDateString();
                    if (! is_string($metadata['start_on'] ?? null) || ! is_string($metadata['end_on'] ?? null)
                        || $metadata['start_on'] > $today || $metadata['end_on'] < $today) {
                        continue;
                    }
                    if (! $this->assigned($actor, $link)) {
                        continue;
                    }
                    // A reviewed tax address remains the sole tax input. Assignment alone cannot authorize a new rate.
                    $address = ['address1' => $metadata['address'] ?? '', 'city' => $metadata['city'] ?? '',
                        'provinceCode' => $metadata['state'] ?? '', 'countryCode' => 'US', 'zip' => $metadata['postal_code'] ?? ''];
                    $taxReady = Contract::hash([$address]) === Contract::hash([$settings['shopify']['tax_address'] ?? null])
                        && strtotime($settings['tax_valid_until'] ?? '') > time();
                    $matches[$register->id] = ['register_id' => $register->id, 'event_id' => (int) $link->event_id,
                        'name' => $metadata['name'], 'source' => $link->provider, 'source_id' => $link->external_id,
                        'address' => $address, 'start_on' => $metadata['start_on'], 'end_on' => $metadata['end_on'],
                        'tax_ready' => $taxReady];
                }
            } catch (\Throwable) {
                // No stale assignment is used when a source cannot be verified.
                $issues[] = 'event_assignment_unavailable';
            }
        }
        $matches = array_values($matches);

        return ['status' => count($matches) === 1 ? 'matched' : (count($matches) > 1 ? 'choose_location' : 'no_current_assignment'),
            'recommended_register_id' => count($matches) === 1 && $issues === [] && $matches[0]['tax_ready'] ? $matches[0]['register_id'] : null,
            'assignments' => $matches, 'issues' => array_values(array_unique($issues)), 'observed_at' => now()->toIso8601String()];
    }

    private function assignedThroughEvents(array $actor, string $id): bool
    {
        $tenant = Tenant::findOrFail($actor['tenant_id']);
        $user = User::findOrFail($actor['actor_id']);
        $providers = app(OperationsProviders::class);
        foreach (TeamProviderConnection::query()->where('tenant_id', $actor['tenant_id'])->where('user_id', $actor['actor_id'])
            ->where('provider', 'asana')->where('status', 'connected')->get() as $connection) {
            // Verify current area membership and ownership of this exact personal connection.
            app(OperationsAccess::class)->connection($tenant, $user, $connection->id);
            $task = $providers->asana($connection, 'tasks/'.$id, ['opt_fields' => EventTasks::FIELDS])['data'] ?? [];
            if (($task['gid'] ?? null) !== $id || ($task['completed'] ?? null) !== false
                || (string) ($task['workspace']['gid'] ?? '') !== (string) ($connection->settings['source']['asana_workspace'] ?? '')
                || ! array_intersect($providers->selectedProjects($connection), array_map('strval', array_column(array_column($task['memberships'] ?? [], 'project'), 'gid')))) {
                continue;
            }
            if ((string) ($task['assignee']['gid'] ?? '') === (string) ($connection->settings['external_user_id'] ?? '')
                && ! empty($connection->settings['external_user_id'])) {
                return true;
            }
        }

        return false;
    }

    private function assigned(array $actor, object $link): bool
    {
        if ($link->provider === 'asana') {
            $connection = $this->asana->connection($actor['tenant_id'], $actor['actor_id']);
            if (! $connection || $connection->status !== 'connected') {
                return $this->assignedThroughEvents($actor, $link->external_id);
            }
            $task = $this->asana->task($connection, $link->external_id);

            return ($task['completed'] ?? null) === false
                && (string) ($task['assignee']['gid'] ?? '') === (string) $connection->external_user_id;
        }
        $user = User::findOrFail($actor['actor_id']);
        $tenant = Tenant::findOrFail($actor['tenant_id']);
        $context = app(TeamWorkAccess::class)->context($user, $tenant);
        $state = app(TeamWorkRepository::class)->state($actor['tenant_id'], false);
        $task = app(WorkGraph::class)->read($state, $context, 'task', $link->external_id);

        return empty($task['completed_at']) && empty($task['deleted_at'])
            && (string) ($task['assignee_id'] ?? '') === (string) $actor['actor_id'];
    }
}
