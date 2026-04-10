<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDiscoveryPage extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'page_key',
        'page_type',
        'title',
        'meta_description',
        'summary',
        'intent_label',
        'audience_type',
        'recommended_domain_role',
        'canonical_path',
        'cta_label',
        'cta_url',
        'service_regions',
        'keywords',
        'faq_items',
        'metadata',
        'position',
        'is_public',
        'is_indexable',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'service_regions' => 'array',
        'keywords' => 'array',
        'faq_items' => 'array',
        'metadata' => 'array',
        'position' => 'integer',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
