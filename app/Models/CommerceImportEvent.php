<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceImportEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'commerce_import_run_id' => 'integer', 'context' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CommerceImportRun::class, 'commerce_import_run_id');
    }
}
