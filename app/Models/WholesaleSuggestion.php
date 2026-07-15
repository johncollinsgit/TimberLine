<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleSuggestion extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'confidence' => 'integer',
            'supporting_evidence' => 'array',
            'estimated_opportunity' => 'decimal:2',
            'suggested_follow_up_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WholesaleSuggestionDecision::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(WholesaleFollowUp::class);
    }
}
