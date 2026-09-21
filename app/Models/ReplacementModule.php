<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReplacementModule extends Model
{
    use BelongsToTenant;

    public const STATUSES = [
        'draft',
        'importing',
        'reconciled',
        'shadow_testing',
        'armed',
        'armed_for_pilot',
        'active',
        'live_verified',
        'rolled_back',
        'blocked',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'shopify_store_id' => 'integer',
            'lock_version' => 'integer',
            'last_verified_at' => 'datetime',
            'ready_at' => 'datetime',
            'activated_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }

    public function sourceSnapshots(): HasMany
    {
        return $this->hasMany(ReplacementSourceSnapshot::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ReplacementImportBatch::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ReplacementEvidence::class);
    }

    public function activationRuns(): HasMany
    {
        return $this->hasMany(ReplacementActivationRun::class);
    }

    public function latestImportBatch(): HasOne
    {
        return $this->hasOne(ReplacementImportBatch::class)->latestOfMany();
    }

    public function latestActivationRun(): HasOne
    {
        return $this->hasOne(ReplacementActivationRun::class)->latestOfMany();
    }
}
