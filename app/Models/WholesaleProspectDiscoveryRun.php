<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleProspectDiscoveryRun extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'categories' => 'array',
            'search_phrases' => 'array',
            'website_enrichment' => 'boolean',
            'instagram_enrichment' => 'boolean',
            'estimated_api_cost' => 'decimal:4',
            'actual_api_cost' => 'decimal:4',
            'large_search_confirmed' => 'boolean',
            'research_date' => 'date',
            'source_log' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'research_usage_reconciled_at' => 'datetime',
        ];
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(WholesaleProspect::class, 'discovery_run_id');
    }
}
