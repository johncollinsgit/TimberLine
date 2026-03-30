<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMarketingSetting extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'description',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'value' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
