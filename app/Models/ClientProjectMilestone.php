<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectMilestone extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_project_id',
        'client_project_phase_id',
        'title',
        'summary',
        'status',
        'starts_on',
        'due_on',
        'completed_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ClientProjectPhase::class, 'client_project_phase_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
