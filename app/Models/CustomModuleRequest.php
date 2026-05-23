<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomModuleRequest extends Model
{
    use HasTenantScope;

    public const STATUSES = [
        'new',
        'needs_discovery',
        'quoted',
        'approved',
        'in_development',
        'in_review',
        'installed',
        'converted_to_reusable_module',
        'declined',
        'archived',
    ];

    public const MOBILE_RELEVANCE_OPTIONS = [
        'none',
        'future_mobile_companion',
        'android',
        'ios',
        'both',
        'field_work',
        'undecided',
    ];

    protected $fillable = [
        'tenant_id',
        'requested_by_user_id',
        'related_module_key',
        'title',
        'problem_summary',
        'current_workaround',
        'desired_outcome',
        'tools_involved',
        'users_impacted',
        'frequency',
        'urgency',
        'budget_range',
        'reusable_module_interest',
        'mobile_relevance',
        'status',
        'landlord_notes',
        'next_action',
        'reviewed_at',
        'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'reusable_module_interest' => 'boolean',
            'reviewed_at' => 'datetime',
            'reviewed_by_user_id' => 'integer',
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

    public function statusLabel(): string
    {
        return match ((string) $this->status) {
            'needs_discovery' => 'Needs discovery',
            'in_development' => 'In development',
            'in_review' => 'In review',
            'converted_to_reusable_module' => 'Converted to reusable module',
            default => str((string) $this->status)->replace('_', ' ')->headline()->toString(),
        };
    }

    public function mobileRelevanceLabel(): string
    {
        return match ((string) $this->mobile_relevance) {
            'none' => 'No mobile relevance',
            'future_mobile_companion' => 'Future mobile companion',
            'android' => 'Android planning',
            'ios' => 'iPhone/iOS planning',
            'both' => 'Android and iOS planning',
            'field_work' => 'Field work/mobile workflow',
            default => 'Undecided',
        };
    }
}
