<?php

namespace App\Models;

use App\Models\Concerns\TracksLegacyCandleCashCompatibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandleCashTaskCompletion extends Model
{
    use TracksLegacyCandleCashCompatibility;

    protected $fillable = [
        'candle_cash_task_id',
        'marketing_profile_id',
        'status',
        'completion_key',
        'request_key',
        'reward_amount',
        'reward_points',
        'reward_candle_cash',
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
        'reward_candle_cash' => 'integer',
        'submission_payload' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'awarded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getRewardCandleCashAttribute($value): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        if (array_key_exists('reward_points', $this->attributes) && $this->attributes['reward_points'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_task_completions.reward_points', 'fallback_read', __METHOD__);
        }

        return (int) ($this->attributes['reward_points'] ?? 0);
    }

    public function setRewardCandleCashAttribute($value): void
    {
        $normalized = max(0, (int) $value);

        $this->attributes['reward_candle_cash'] = $normalized;
        $this->attributes['reward_points'] = $normalized;
    }

    public function getRewardPointsAttribute($value): int
    {
        if ($value !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_task_completions.reward_points', 'legacy_read', __METHOD__);

            return (int) $value;
        }

        if (array_key_exists('reward_candle_cash', $this->attributes) && $this->attributes['reward_candle_cash'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_task_completions.reward_points', 'legacy_read', __METHOD__);
        }

        return (int) ($this->attributes['reward_candle_cash'] ?? 0);
    }

    public function setRewardPointsAttribute($value): void
    {
        $this->recordLegacyCandleCashCompatibility('candle_cash_task_completions.reward_points', 'legacy_write', __METHOD__);

        $normalized = max(0, (int) $value);

        $this->attributes['reward_points'] = $normalized;
        $this->attributes['reward_candle_cash'] = $normalized;
    }

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

    public function events(): HasMany
    {
        return $this->hasMany(CandleCashTaskEvent::class, 'candle_cash_task_completion_id');
    }
}
