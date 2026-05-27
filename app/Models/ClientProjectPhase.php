<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientProjectPhase extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_project_id',
        'name',
        'summary',
        'status',
        'starts_on',
        'due_on',
        'completed_at',
        'percent_complete',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'percent_complete' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ClientProjectMilestone::class)->orderBy('sort_order')->orderBy('due_on')->orderBy('id');
    }
}
