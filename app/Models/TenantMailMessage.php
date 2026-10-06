<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMailMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'tenant_mailbox_id', 'folder', 'direction', 'from_address', 'to_address', 'subject', 'text_body', 'provider_message_id', 'thread_key', 'delivery_status', 'read_at', 'starred_at', 'occurred_at'];

    protected $casts = ['read_at' => 'datetime', 'starred_at' => 'datetime', 'occurred_at' => 'datetime'];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(TenantMailbox::class, 'tenant_mailbox_id');
    }
}
