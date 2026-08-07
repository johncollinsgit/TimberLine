<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceImportRun extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'commerce_source_id' => 'integer',
            'initiated_by_user_id' => 'integer',
            'requested_resources' => 'array',
            'counts' => 'array',
            'report' => 'array',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CommerceSource::class, 'commerce_source_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommerceImportEvent::class);
    }
}
