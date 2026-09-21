<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplacementImportBatch extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'replacement_module_id' => 'integer',
            'replacement_source_snapshot_id' => 'integer',
            'source_count' => 'integer',
            'imported_count' => 'integer',
            'warning_count' => 'integer',
            'rejected_count' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ReplacementModule::class, 'replacement_module_id');
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(ReplacementSourceSnapshot::class, 'replacement_source_snapshot_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ReplacementImportRow::class);
    }
}
