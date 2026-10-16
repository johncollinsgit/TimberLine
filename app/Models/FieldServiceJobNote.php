<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldServiceJobNote extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_service_job_id',
        'created_by_user_id',
        'body',
        'status_update',
        'noted_at',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'created_by_user_id' => 'integer',
        'noted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('active_ai_note', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNull($query->getModel()->qualifyColumn('metadata').'->ai_branch_reversed_at'));
        // Any subsequent application edit permanently invalidates AI undo.
        static::updating(function (self $note): void {
            if (data_get($note->getOriginal('metadata'), 'ai_branch_action_id')) {
                $note->metadata = [...(array) $note->metadata, 'ai_branch_reversible' => false];
            }
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(FieldServiceJobPhoto::class, 'field_service_job_note_id');
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'field_service_job_note_mentions')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
