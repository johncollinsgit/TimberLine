<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProfileScore extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'score_type',
        'score',
        'reasons_json',
        'calculated_at',
    ];

    protected $casts = [
        'reasons_json' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
