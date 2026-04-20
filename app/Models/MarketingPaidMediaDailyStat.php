<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingPaidMediaDailyStat extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'platform',
        'account_id',
        'metric_date',
        'campaign_id',
        'campaign_name',
        'ad_set_id',
        'ad_set_name',
        'ad_id',
        'ad_name',
        'spend',
        'impressions',
        'clicks',
        'reach',
        'purchases',
        'purchase_value',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'row_fingerprint',
        'raw_payload',
        'last_synced_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'metric_date' => 'date',
        'spend' => 'decimal:2',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'reach' => 'integer',
        'purchases' => 'integer',
        'purchase_value' => 'decimal:2',
        'raw_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
