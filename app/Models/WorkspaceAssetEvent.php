<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WorkspaceAssetEvent extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'workspace_asset_id', 'actor_user_id', 'action', 'context', 'occurred_at'];

    protected $casts = [
        'tenant_id' => 'integer',
        'workspace_asset_id' => 'integer',
        'actor_user_id' => 'integer',
        'context' => 'encrypted:array',
        'occurred_at' => 'datetime',
    ];
}
