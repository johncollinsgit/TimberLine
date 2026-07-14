<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceJobNotification extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_job_id', 'field_service_job_note_id', 'user_id',
        'channel', 'event_type', 'event_key', 'status', 'provider_message_id', 'failure_code', 'sent_at', 'read_at', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'field_service_job_note_id' => 'integer',
        'user_id' => 'integer',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJobNote::class, 'field_service_job_note_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
