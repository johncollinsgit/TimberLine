<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantWorkspaceChangeRequest extends Model
{
    use HasTenantScope;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'requested_by_user_id',
        'requested_template_key',
        'requested_context',
        'request_note',
        'status',
        'reviewed_by_user_id',
        'decision_note',
        'requested_at',
        'reviewed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'requested_context' => 'array',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
