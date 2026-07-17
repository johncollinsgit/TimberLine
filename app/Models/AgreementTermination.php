<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementTermination extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'effective_at' => 'datetime', 'completed_at' => 'datetime',
            'export_window_ends_at' => 'datetime', 'export_requested_at' => 'datetime',
            'export_completed_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
