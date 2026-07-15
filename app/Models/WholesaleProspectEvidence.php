<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleProspectEvidence extends Model
{
    use BelongsToTenant;

    protected $table = 'wholesale_prospect_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'supports_fit' => 'boolean',
            'observed_at' => 'datetime',
            'source_reference' => 'array',
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(WholesaleProspect::class, 'wholesale_prospect_id');
    }
}
