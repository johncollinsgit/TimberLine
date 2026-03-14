<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashTaskEvent extends Model
{
    protected $fillable = [
        'candle_cash_task_id',
        'marketing_profile_id',
        'candle_cash_task_completion_id',
        'verification_mode',
        'source_type',
        'source_id',
        'source_event_key',
        'status',
        'reward_awarded',
        'blocked_reason',
        'duplicate_hits',
        'duplicate_last_seen_at',
        'occurred_at',
        'processed_at',
        'awarded_at',
        'metadata',
    ];

    protected $casts = [
        'reward_awarded' => 'boolean',
        'duplicate_hits' => 'integer',
        'duplicate_last_seen_at' => 'datetime',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'awarded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CandleCashTask::class, 'candle_cash_task_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskCompletion::class, 'candle_cash_task_completion_id');
    }
}
