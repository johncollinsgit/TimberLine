<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSupportTicketMessage extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_support_ticket_id', 'tenant_id', 'user_id', 'author_context', 'body'];

    protected function casts(): array
    {
        return ['tenant_support_ticket_id' => 'integer', 'tenant_id' => 'integer', 'user_id' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TenantSupportTicket::class, 'tenant_support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
