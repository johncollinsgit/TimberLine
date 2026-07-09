<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectTicketComment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'client_project_ticket_id',
        'author_name',
        'body',
        'public_visible',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'client_project_ticket_id' => 'integer',
            'public_visible' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ClientProjectTicket::class, 'client_project_ticket_id');
    }
}
