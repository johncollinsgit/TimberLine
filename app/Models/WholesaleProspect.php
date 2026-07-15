<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleProspect extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'secondary_categories' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'discovered_at' => 'datetime',
            'fit_score' => 'integer',
            'fit_confidence' => 'integer',
            'fit_explanation' => 'array',
            'last_reviewed_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'next_action_at' => 'datetime',
            'do_not_contact' => 'boolean',
            'converted_at' => 'datetime',
            'source_snapshot' => 'array',
        ];
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(WholesaleProspectDiscoveryRun::class, 'discovery_run_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WholesaleProspectEvidence::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(WholesaleFollowUp::class, 'target_key', 'public_id')
            ->where('target_type', 'prospect');
    }

    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(WholesaleAccount::class, 'converted_wholesale_account_id');
    }
}
