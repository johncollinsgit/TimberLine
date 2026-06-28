<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProfileScentQuizResult extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'tenant_id',
        'quiz_version',
        'axis_scores',
        'dominant_traits',
        'headline',
        'personality_title',
        'personality_body',
        'answers',
        'completed_at',
    ];

    protected $casts = [
        'marketing_profile_id' => 'integer',
        'tenant_id' => 'integer',
        'axis_scores' => 'array',
        'dominant_traits' => 'array',
        'answers' => 'array',
        'completed_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
