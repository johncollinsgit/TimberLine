<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceTimeBreak extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'field_service_time_session_id', 'client_uuid', 'started_at', 'ended_at', 'duration_seconds'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_time_session_id' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'duration_seconds' => 'integer'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(FieldServiceTimeSession::class, 'field_service_time_session_id');
    }
}
