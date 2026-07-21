<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceTaskEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_task_id', 'actor_user_id', 'event_type',
        'from_status', 'to_status', 'note', 'idempotency_key', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_task_id' => 'integer',
        'actor_user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(FieldServiceTask::class, 'field_service_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
