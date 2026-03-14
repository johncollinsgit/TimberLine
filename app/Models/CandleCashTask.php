<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandleCashTask extends Model
{
    protected $fillable = [
        'handle',
        'title',
        'description',
        'reward_amount',
        'enabled',
        'display_order',
        'task_type',
        'verification_mode',
        'auto_award',
        'action_url',
        'button_text',
        'completion_rule',
        'max_completions_per_customer',
        'requires_manual_approval',
        'requires_customer_submission',
        'icon',
        'start_date',
        'end_date',
        'eligibility_type',
        'required_customer_tags',
        'required_membership_status',
        'visible_to_noneligible_customers',
        'locked_message',
        'locked_cta_text',
        'locked_cta_url',
        'campaign_key',
        'external_object_id',
        'verification_window_hours',
        'matching_rules',
        'metadata',
        'admin_notes',
        'archived_at',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'enabled' => 'boolean',
        'display_order' => 'integer',
        'auto_award' => 'boolean',
        'completion_rule' => 'array',
        'max_completions_per_customer' => 'integer',
        'requires_manual_approval' => 'boolean',
        'requires_customer_submission' => 'boolean',
        'verification_window_hours' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'required_customer_tags' => 'array',
        'matching_rules' => 'array',
        'metadata' => 'array',
        'visible_to_noneligible_customers' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function completions(): HasMany
    {
        return $this->hasMany(CandleCashTaskCompletion::class, 'candle_cash_task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CandleCashTaskEvent::class, 'candle_cash_task_id');
    }
}
