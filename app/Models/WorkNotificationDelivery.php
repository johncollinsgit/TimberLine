<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkNotificationDelivery extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'work_notification_id',
        'tenant_id',
        'user_id',
        'channel',
        'status',
        'error',
        'metadata',
        'delivered_at',
    ];

    protected $casts = [
        'work_notification_id' => 'integer',
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'metadata' => 'array',
        'delivered_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(WorkNotification::class, 'work_notification_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
