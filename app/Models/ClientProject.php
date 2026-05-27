<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientProject extends Model
{
    protected $fillable = [
        'tenant_id',
        'title',
        'summary',
        'status',
        'health',
        'starts_on',
        'due_on',
        'completed_at',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ClientProjectPhase::class)->orderBy('sort_order')->orderBy('id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ClientProjectMilestone::class)->orderBy('sort_order')->orderBy('due_on')->orderBy('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ClientProjectUpdate::class)->latest('published_at')->latest('id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ClientProjectLink::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeForTenantId(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
