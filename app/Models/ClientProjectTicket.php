<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ClientProjectTicket extends Model
{
    use HasTenantScope;

    public const TYPES = [
        'feature',
        'app_request',
        'change_request',
        'question',
    ];

    public const STATUSES = [
        'new',
        'needs_discovery',
        'scoped',
        'approved',
        'in_progress',
        'waiting_on_customer',
        'in_review',
        'done',
        'declined',
        'archived',
    ];

    public const URGENCIES = [
        'low',
        'normal',
        'high',
        'urgent',
    ];

    protected $fillable = [
        'tenant_id',
        'client_project_id',
        'client_project_phase_id',
        'client_project_milestone_id',
        'custom_module_request_id',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'type',
        'title',
        'problem_summary',
        'desired_outcome',
        'scope_notes',
        'urgency',
        'priority',
        'status',
        'customer_visible',
        'landlord_notes',
        'reviewed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'client_project_id' => 'integer',
            'client_project_phase_id' => 'integer',
            'client_project_milestone_id' => 'integer',
            'custom_module_request_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'customer_visible' => 'boolean',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ClientProjectPhase::class, 'client_project_phase_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ClientProjectMilestone::class, 'client_project_milestone_id');
    }

    public function customModuleRequest(): BelongsTo
    {
        return $this->belongsTo(CustomModuleRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ClientProjectTicketTask::class)->orderBy('sort_order')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ClientProjectTicketComment::class)->latest('id');
    }

    public function publicComments(): HasMany
    {
        return $this->hasMany(ClientProjectTicketComment::class)
            ->where('public_visible', true)
            ->latest('id');
    }

    public function feedbackVotes(): HasMany
    {
        return $this->hasMany(ClientProjectTicketVote::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(ClientProjectTicketReference::class)->orderBy('sort_order')->orderBy('id');
    }

    public function statusLabel(): string
    {
        return Str::headline(str_replace('_', ' ', (string) $this->status));
    }

    public function typeLabel(): string
    {
        return Str::headline(str_replace('_', ' ', (string) $this->type));
    }
}
