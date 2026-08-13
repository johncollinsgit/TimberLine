<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePlanTemplate extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'created_by_user_id', 'slug', 'name', 'badge', 'description', 'status', 'current_version', 'sort_order', 'published_at'];

    protected $casts = ['tenant_id' => 'integer', 'created_by_user_id' => 'integer', 'current_version' => 'integer', 'sort_order' => 'integer', 'published_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ServicePlanVersion::class);
    }
}
