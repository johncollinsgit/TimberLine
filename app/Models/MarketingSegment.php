<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingSegment extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'channel_scope',
        'rules_json',
        'is_system',
        'last_previewed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'rules_json' => 'array',
        'is_system' => 'boolean',
        'last_previewed_at' => 'datetime',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class, 'segment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
