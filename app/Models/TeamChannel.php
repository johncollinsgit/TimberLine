<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamChannel extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'field_service_job_id', 'created_by_user_id', 'kind', 'name', 'direct_key', 'archived_at'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_job_id' => 'integer', 'created_by_user_id' => 'integer', 'archived_at' => 'datetime'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_channel_members')->withPivot(['tenant_id', 'last_read_at', 'muted_at'])->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeamMessage::class);
    }
}
