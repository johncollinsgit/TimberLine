<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassEnrollment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'scheduled_class_id', 'marketing_profile_id', 'name', 'email', 'normalized_email',
        'phone', 'normalized_phone', 'seats', 'status', 'email_reminders_enabled', 'sms_reminders_enabled',
        'notes', 'source', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'scheduled_class_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'seats' => 'integer',
        'email_reminders_enabled' => 'boolean',
        'sms_reminders_enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ClassReminder::class);
    }
}
