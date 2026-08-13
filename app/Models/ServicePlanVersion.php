<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePlanVersion extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'service_plan_template_id', 'created_by_user_id', 'version', 'snapshot', 'content_hash', 'published_at'];

    protected $casts = ['tenant_id' => 'integer', 'service_plan_template_id' => 'integer', 'created_by_user_id' => 'integer', 'version' => 'integer', 'snapshot' => 'array', 'published_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ServicePlanTemplate::class, 'service_plan_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ServicePlanVersionAddon::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ServicePlanVersionMedia::class)->orderBy('sort_order');
    }
}
