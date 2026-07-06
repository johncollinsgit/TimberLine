<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkNotification extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'actor_user_id',
        'category',
        'title',
        'body',
        'item_type',
        'item_id',
        'deep_link',
        'data',
        'read_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'actor_user_id' => 'integer',
        'item_id' => 'integer',
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WorkNotificationDelivery::class);
    }
}
