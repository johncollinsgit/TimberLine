<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FleetTrackingPolicyAcknowledgement extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'user_id', 'policy_version', 'policy_sha256', 'accepted_at', 'acceptance_source', 'device_context'];

    protected $casts = ['tenant_id' => 'integer', 'user_id' => 'integer', 'accepted_at' => 'datetime', 'device_context' => 'array'];
}
