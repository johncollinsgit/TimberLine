<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingTemplateInstance extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'template_definition_id',
        'campaign_id',
        'tenant_id',
        'store_key',
        'channel',
        'name',
        'subject',
        'body',
        'sections',
        'advanced_html',
        'rendered_html',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'template_definition_id' => 'integer',
        'campaign_id' => 'integer',
        'tenant_id' => 'integer',
        'sections' => 'array',
        'metadata' => 'array',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplateDefinition::class, 'template_definition_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
