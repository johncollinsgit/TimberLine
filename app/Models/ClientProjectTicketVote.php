<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectTicketVote extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'client_project_ticket_id',
        'voter_hash',
        'ip_hash',
        'user_agent_hash',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'client_project_ticket_id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ClientProjectTicket::class, 'client_project_ticket_id');
    }
}
