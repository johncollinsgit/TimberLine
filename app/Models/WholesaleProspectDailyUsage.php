<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WholesaleProspectDailyUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'wholesale_prospect_daily_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'research_date' => 'date',
            'reserved_results' => 'integer',
            'researched_results' => 'integer',
            'queued_runs' => 'integer',
            'completed_runs' => 'integer',
        ];
    }
}
