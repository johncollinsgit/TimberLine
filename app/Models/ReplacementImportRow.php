<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplacementImportRow extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'replacement_import_batch_id' => 'integer',
            'row_number' => 'integer',
            'target_id' => 'integer',
            'messages' => 'array',
            'payload' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReplacementImportBatch::class, 'replacement_import_batch_id');
    }
}
