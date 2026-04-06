<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessagingConversation extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'channel',
        'marketing_profile_id',
        'phone',
        'email',
        'subject',
        'status',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
        'unread_count',
        'assigned_to',
        'source_type',
        'source_id',
        'source_context',
        'last_message_preview',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'unread_count' => 'integer',
        'assigned_to' => 'integer',
        'source_context' => 'array',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessagingConversationMessage::class, 'conversation_id');
    }
}
