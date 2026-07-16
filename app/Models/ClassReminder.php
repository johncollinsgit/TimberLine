<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassReminder extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'class_enrollment_id', 'created_by_user_id', 'channel', 'scheduled_for',
        'status', 'message', 'sent_at', 'provider_metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'class_enrollment_id' => 'integer',
        'created_by_user_id' => 'integer',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'provider_metadata' => 'array',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ClassEnrollment::class, 'class_enrollment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
