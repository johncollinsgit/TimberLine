<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledClass extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'title', 'slug', 'category', 'description', 'location', 'starts_at', 'ends_at',
        'capacity', 'price', 'status', 'registration_open', 'image_url', 'reminder_offsets', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
        'price' => 'decimal:2',
        'registration_open' => 'boolean',
        'reminder_offsets' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class);
    }

    public function confirmedEnrollments(): HasMany
    {
        return $this->enrollments()->where('status', 'confirmed');
    }

    public function getSeatsTakenAttribute(): int
    {
        return (int) ($this->confirmed_enrollments_sum_seats ?? $this->confirmedEnrollments()->sum('seats'));
    }

    public function getSeatsRemainingAttribute(): int
    {
        return max(0, (int) $this->capacity - $this->seats_taken);
    }
}
