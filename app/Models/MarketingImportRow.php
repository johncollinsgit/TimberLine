<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingImportRow extends Model
{
    protected $fillable = [
        'marketing_import_run_id',
        'row_number',
        'external_key',
        'status',
        'messages',
        'payload',
    ];

    protected $casts = [
        'messages' => 'array',
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MarketingImportRun::class, 'marketing_import_run_id');
    }
}
