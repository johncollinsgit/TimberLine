<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyImportRun extends Model
{
    protected $fillable = [
        'store_key',
        'source',
        'is_dry_run',
        'imported_count',
        'updated_count',
        'lines_count',
        'merged_lines_count',
        'mapping_exceptions_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'is_dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
