<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashTaskCompletion extends Model
{
    protected $fillable = [
        'candle_cash_task_id',
        'marketing_profile_id',
        'status',
        'completion_key',
        'request_key',
        'reward_amount',
        'reward_points',
        'source_type',
        'source_id',
        'proof_url',
        'proof_text',
        'submission_payload',
        'blocked_reason',
        'review_notes',
        'approved_by',
        'candle_cash_transaction_id',
        'started_at',
        'submitted_at',
        'reviewed_at',
        'awarded_at',
        'metadata',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'reward_points' => 'integer',
        'submission_payload' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CandleCashTransaction::class, 'candle_cash_transaction_id');
    }
}
