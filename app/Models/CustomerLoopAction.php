<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoopAction extends Model
{
    use BelongsToTenant;

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_SNOOZED = 'snoozed';

    protected $fillable = ['tenant_id', 'customer_loop_activity_id', 'marketing_profile_id', 'assigned_to_user_id', 'created_by_user_id', 'action_type', 'status', 'title', 'reason', 'draft_body', 'due_at', 'prepared_at', 'completed_at', 'snoozed_until', 'safe_context'];

    protected $casts = ['safe_context' => 'array', 'due_at' => 'datetime', 'prepared_at' => 'datetime', 'completed_at' => 'datetime', 'snoozed_until' => 'datetime'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(CustomerLoopActivity::class, 'customer_loop_activity_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
