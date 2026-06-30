<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProfileScentQuizResult extends Model
{
    public const SHARE_CARD_VERSION = 'mf-scent-v4';

    protected $fillable = [
        'marketing_profile_id',
        'tenant_id',
        'quiz_version',
        'axis_scores',
        'dominant_traits',
        'headline',
        'personality_title',
        'personality_body',
        'public_share_token',
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

    public function publicShareRevision(): string
    {
        $revisionBasis = implode('|', [
            (string) $this->id,
            (string) ($this->updated_at?->format('YmdHis.u') ?? ''),
            (string) ($this->completed_at?->format('YmdHis.u') ?? ''),
            (string) ($this->headline ?? ''),
            (string) ($this->personality_title ?? ''),
            (string) ($this->personality_body ?? ''),
            json_encode($this->dominant_traits ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            json_encode($this->axis_scores ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ]);

        return substr(sha1($revisionBasis), 0, 12);
    }

    public function publicShareCardVersion(): string
    {
        return self::SHARE_CARD_VERSION.'-'.$this->publicShareRevision();
    }
}
