<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectTicketReference extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'client_project_ticket_id',
        'label',
        'url',
        'reference_type',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'client_project_ticket_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ClientProjectTicket::class, 'client_project_ticket_id');
    }
}
