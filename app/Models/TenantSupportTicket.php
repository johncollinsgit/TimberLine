<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSupportTicket extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'created_by_user_id', 'assigned_to_user_id', 'subject',
        'category', 'priority', 'status', 'source_type', 'dedupe_key', 'resolution_summary', 'resolved_at', 'metadata', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'created_by_user_id' => 'integer', 'assigned_to_user_id' => 'integer', 'last_activity_at' => 'datetime', 'resolved_at' => 'datetime', 'metadata' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantSupportTicketMessage::class)->orderBy('id');
    }
}
