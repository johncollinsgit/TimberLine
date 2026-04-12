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
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'approved_at' => 'datetime',
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

