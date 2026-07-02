<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceVehicle extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'name',
        'identifier',
        'status',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
