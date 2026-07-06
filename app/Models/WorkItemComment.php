<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItemComment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'item_type',
        'item_id',
        'user_id',
        'body',
        'mentioned_user_ids',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'item_id' => 'integer',
        'user_id' => 'integer',
        'mentioned_user_ids' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
