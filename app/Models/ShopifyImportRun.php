<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyImportRun extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'source',
        'is_dry_run',
        'imported_count',
        'updated_count',
        'lines_count',
        'merged_lines_count',
        'mapping_exceptions_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'is_dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
