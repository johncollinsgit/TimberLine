<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectTicketTask extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'client_project_ticket_id',
        'client_project_phase_id',
        'title',
        'details',
        'owner_type',
        'status',
        'due_on',
        'completed_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'client_project_ticket_id' => 'integer',
            'client_project_phase_id' => 'integer',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ClientProjectTicket::class, 'client_project_ticket_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ClientProjectPhase::class, 'client_project_phase_id');
    }
}
