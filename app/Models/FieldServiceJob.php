<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldServiceJob extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'assigned_user_id',
        'title',
        'status',
        'operational_status',
        'status_source',
        'customer_name',
        'customer_email',
        'customer_phone',
        'lock_box_code',
        'service_address_line_1',
        'service_address_line_2',
        'service_city',
        'service_state',
        'service_postal_code',
        'service_country',
        'description',
        'scheduled_for',
        'completed_at',
        'last_financial_activity_at',
        'archived_at',
        'external_source',
        'external_id',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'assigned_user_id' => 'integer',
        'scheduled_for' => 'datetime',
        'completed_at' => 'datetime',
        'last_financial_activity_at' => 'datetime',
        'archived_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'field_service_job_participants')
            ->withPivot(['tenant_id', 'role', 'following'])
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(FieldServiceTask::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(FieldServiceMaterial::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(FieldServiceJobPhoto::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FieldServiceJobNote::class);
    }

    public function financialDocuments(): HasMany
    {
        return $this->hasMany(FieldServiceFinancialDocument::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceAsset::class, 'field_service_job_workspace_asset')
            ->withPivot(['tenant_id', 'linked_by_user_id'])
            ->withTimestamps();
    }
}
