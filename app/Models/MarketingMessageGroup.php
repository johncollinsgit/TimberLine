<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingMessageGroup extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'name',
        'channel',
        'is_reusable',
        'is_system',
        'system_key',
        'description',
        'created_by',
        'last_used_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'is_reusable' => 'boolean',
        'is_system' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(MarketingMessageGroupMember::class, 'marketing_message_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
