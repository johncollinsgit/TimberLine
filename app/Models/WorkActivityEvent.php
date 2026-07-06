<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkActivityEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'item_type',
        'item_id',
        'actor_user_id',
        'event_type',
        'title',
        'body',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'item_id' => 'integer',
        'actor_user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
