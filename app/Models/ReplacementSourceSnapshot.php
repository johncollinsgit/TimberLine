<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ReplacementSourceSnapshot extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'replacement_module_id' => 'integer',
            'record_count' => 'integer',
            'payload' => 'array',
            'provenance' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ReplacementModule::class, 'replacement_module_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Replacement source snapshots are immutable. Capture a new snapshot.'));
        static::deleting(fn () => throw new RuntimeException('Replacement source snapshots are immutable.'));
    }
}
