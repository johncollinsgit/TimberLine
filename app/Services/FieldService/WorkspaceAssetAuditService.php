<?php

namespace App\Services\FieldService;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Models\WorkspaceAssetEvent;

class WorkspaceAssetAuditService
{
    /** @param array<string,mixed> $context */
    public function record(Tenant $tenant, ?WorkspaceAsset $asset, ?User $actor, string $action, array $context = []): WorkspaceAssetEvent
    {
        abort_if($asset && (int) $asset->tenant_id !== (int) $tenant->id, 404);

        return WorkspaceAssetEvent::query()->create([
            'tenant_id' => (int) $tenant->id,
            'workspace_asset_id' => $asset?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
