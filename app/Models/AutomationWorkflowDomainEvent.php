<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWorkflowDomainEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event_key',
        'event_type',
        'subject_type',
        'subject_id',
        'payload',
        'occurred_at',
        'consumed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'payload' => 'encrypted:array',
        'occurred_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected $hidden = [
        'payload',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
