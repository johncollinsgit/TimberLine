<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyPrivacyWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_MANUAL_REVIEW_REQUIRED = 'manual_review_required';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_IGNORED_INVALID = 'ignored_invalid';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'topic',
        'shop_domain',
        'webhook_id',
        'payload_hash',
        'payload_summary',
        'status',
        'action_required',
        'handled_at',
        'reviewed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'action_required' => 'boolean',
            'handled_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
