<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProfileLink extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'source_type',
        'source_id',
        'source_meta',
        'match_method',
        'confidence',
    ];

    protected $casts = [
        'source_meta' => 'array',
        'confidence' => 'decimal:2',
    ];

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class);
    }
}
