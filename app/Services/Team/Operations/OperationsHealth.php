<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamProviderConnection;

class OperationsHealth
{
    public function area(TeamArea $area, int $userId, ?string $provider = null): array
    {
        $query = TeamProviderConnection::query()->where(['tenant_id' => $area->tenant_id, 'area_id' => $area->id, 'user_id' => $userId]);
        if ($provider) {
            $query->where('provider', $provider);
        }
        $items = $query->get()->map(fn ($c) => ['provider' => $c->provider, 'connection_id' => $c->id, 'status' => $c->status, 'complete' => ($c->sync_state['complete'] ?? false) && $c->status === 'connected', 'last_success_at' => $c->last_success_at?->toIso8601String(), 'lag_seconds' => $c->last_success_at ? (int) $c->last_success_at->diffInSeconds(now()) : null, 'stale' => ! $c->last_success_at || $c->last_success_at->lt(now()->subMinutes(5))])->all();

        return ['sources' => $items, 'configured' => count($items) > 0, 'complete' => count($items) > 0 && collect($items)->every(fn ($i) => $i['complete'] && ! $i['stale']), 'notice' => ! $items ? 'No source is connected. Source coverage is unknown.' : (collect($items)->contains(fn ($i) => ! $i['complete'] || $i['stale']) ? 'Source coverage is partial or stale. Check Connections before relying on completeness.' : null)];
    }
}
