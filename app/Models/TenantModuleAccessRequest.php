<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModuleAccessRequest extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'module_key',
        'status',
        'requested_by',
        'resolved_by',
        'source',
        'request_reason',
        'request_note',
        'decision_note',
        'metadata',
        'requested_at',
        'resolved_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'requested_by' => 'integer',
            'resolved_by' => 'integer',
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
