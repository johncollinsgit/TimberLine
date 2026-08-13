<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantWholesaleSetting extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'shopify_store_id',
        'qualification_mode',
        'product_categories',
        'discovery_keywords',
        'website_enrichment_enabled',
        'prospect_daily_research_limit',
        'prospect_daily_run_limit',
        'prospect_outreach_cooldown_days',
        'confirmed_at',
        'confirmed_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'shopify_store_id' => 'integer',
        'product_categories' => 'array',
        'discovery_keywords' => 'array',
        'website_enrichment_enabled' => 'boolean',
        'prospect_daily_research_limit' => 'integer',
        'prospect_daily_run_limit' => 'integer',
        'prospect_outreach_cooldown_days' => 'integer',
        'confirmed_at' => 'datetime',
        'confirmed_by_user_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shopifyStore(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
