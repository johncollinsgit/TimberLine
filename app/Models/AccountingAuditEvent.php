<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AccountingAuditEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'event_type', 'subject_type', 'subject_id',
        'context', 'occurred_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'actor_user_id' => 'integer',
        'subject_id' => 'integer',
        'context' => 'encrypted:array',
        'occurred_at' => 'datetime',
    ];
}
