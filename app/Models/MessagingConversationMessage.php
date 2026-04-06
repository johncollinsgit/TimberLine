<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingConversationMessage extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'conversation_id',
        'tenant_id',
        'store_key',
        'marketing_profile_id',
        'marketing_message_delivery_id',
        'marketing_email_delivery_id',
        'channel',
        'direction',
        'provider',
        'provider_message_id',
        'dedupe_hash',
        'body',
        'normalized_body',
        'subject',
        'from_identity',
        'to_identity',
        'received_at',
        'sent_at',
        'delivery_status',
        'message_type',
        'operator_read_at',
        'created_by',
        'raw_payload',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'marketing_message_delivery_id' => 'integer',
        'marketing_email_delivery_id' => 'integer',
        'created_by' => 'integer',
        'received_at' => 'datetime',
        'sent_at' => 'datetime',
        'operator_read_at' => 'datetime',
        'raw_payload' => 'array',
        'metadata' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MessagingConversation::class, 'conversation_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function smsDelivery(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageDelivery::class, 'marketing_message_delivery_id');
    }

    public function emailDelivery(): BelongsTo
    {
        return $this->belongsTo(MarketingEmailDelivery::class, 'marketing_email_delivery_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
