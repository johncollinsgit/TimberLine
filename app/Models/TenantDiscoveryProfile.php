<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDiscoveryProfile extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'primary_brand_name',
        'alternate_brand_names',
        'wholesale_brand_label',
        'retail_brand_label',
        'short_brand_summary',
        'long_form_description',
        'support_email',
        'support_phone',
        'social_profiles',
        'primary_logo_url',
        'brand_keywords',
        'why_choose_us_bullets',
        'domain_map',
        'canonical_rules',
        'geography',
        'audience_map',
        'trust_facts',
        'merchant_signals',
        'placeholders',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'alternate_brand_names' => 'array',
        'social_profiles' => 'array',
        'brand_keywords' => 'array',
        'why_choose_us_bullets' => 'array',
        'domain_map' => 'array',
        'canonical_rules' => 'array',
        'geography' => 'array',
        'audience_map' => 'array',
        'trust_facts' => 'array',
        'merchant_signals' => 'array',
        'placeholders' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
