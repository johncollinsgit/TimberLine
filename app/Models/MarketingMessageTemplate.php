<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingMessageTemplate extends Model
{
    protected $fillable = [
        'name',
        'channel',
        'objective',
        'tone',
        'template_text',
        'variables_json',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'variables_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(MarketingCampaignVariant::class, 'template_id');
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
