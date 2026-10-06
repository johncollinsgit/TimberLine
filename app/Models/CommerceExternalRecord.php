<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceExternalRecord extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'commerce_source_id' => 'integer',
            'snapshot' => 'encrypted:array',
            'source_updated_at' => 'datetime',
            'imported_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CommerceSource::class, 'commerce_source_id');
    }
}
