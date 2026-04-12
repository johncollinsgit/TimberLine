<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccessRequest extends Model
{
    protected $fillable = [
        'intent',
        'status',
        'name',
        'email',
        'company',
        'requested_tenant_slug',
        'message',
        'metadata',
        'user_id',
        'tenant_id',
        'approved_by',
        'approved_at',
        'decision_note',
        'rejected_by',
        'rejected_at',
        'rejection_note',
        'activation_email_sent_at',
        'activation_email_last_attempted_at',
        'activation_email_last_attempt_status',
        'activation_email_last_sent_at',
        'activation_email_resend_count',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'activation_email_sent_at' => 'datetime',
            'activation_email_last_attempted_at' => 'datetime',
            'activation_email_last_sent_at' => 'datetime',
            'activation_email_resend_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
