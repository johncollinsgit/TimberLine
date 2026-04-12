<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class TenantOnboardingJourneyEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'final_blueprint_id',
        'event_key',
        'occurred_at',
        'actor_user_id',
        'dedupe_key',
        'payload',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'final_blueprint_id' => 'integer',
        'actor_user_id' => 'integer',
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];
}

