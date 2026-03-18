<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingStorefrontEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'status',
        'issue_type',
        'source_surface',
        'endpoint',
        'request_key',
        'signature_mode',
        'marketing_profile_id',
        'event_instance_id',
        'candle_cash_redemption_id',
        'source_type',
        'source_id',
        'meta',
        'occurred_at',
        'resolution_status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'meta' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class, 'event_instance_id');
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(CandleCashRedemption::class, 'candle_cash_redemption_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
