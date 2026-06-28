<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSocialShareClaim extends Model
{
    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'platform',
        'target_type',
        'target_id',
        'share_url',
        'status',
        'proof_url',
        'proof_text',
        'candle_cash_transaction_id',
        'started_at',
        'claimed_at',
        'awarded_at',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'candle_cash_transaction_id' => 'integer',
        'started_at' => 'datetime',
        'claimed_at' => 'datetime',
        'awarded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CandleCashTransaction::class, 'candle_cash_transaction_id');
    }
}
