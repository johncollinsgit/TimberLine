<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordProspectDiscoveryRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'maximum_results' => 'integer',
            'api_request_count' => 'integer',
            'estimated_api_cost' => 'decimal:4',
            'actual_api_cost' => 'decimal:4',
            'results_discovered' => 'integer',
            'results_created' => 'integer',
            'duplicates_suppressed' => 'integer',
            'website_missing_count' => 'integer',
            'source_log' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
