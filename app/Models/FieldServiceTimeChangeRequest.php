<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceTimeChangeRequest extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'field_service_time_session_id', 'field_service_time_entry_id', 'requested_by_user_id', 'reviewed_by_user_id', 'status', 'before_snapshot', 'requested_snapshot', 'resolution_snapshot', 'reason', 'reviewer_note', 'reviewed_at'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_time_session_id' => 'integer', 'field_service_time_entry_id' => 'integer', 'requested_by_user_id' => 'integer', 'reviewed_by_user_id' => 'integer', 'before_snapshot' => 'array', 'requested_snapshot' => 'array', 'resolution_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(FieldServiceTimeSession::class, 'field_service_time_session_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FieldServiceTimeEntry::class, 'field_service_time_entry_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
