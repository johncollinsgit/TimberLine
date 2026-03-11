<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingMessageGroup extends Model
{
    protected $fillable = [
        'name',
        'channel',
        'is_reusable',
        'description',
        'created_by',
        'last_used_at',
    ];

    protected $casts = [
        'is_reusable' => 'boolean',
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
}
