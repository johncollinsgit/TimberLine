<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingGroupImportRow extends Model
{
    protected $fillable = [
        'marketing_group_import_run_id',
        'row_number',
        'status',
        'external_key',
        'marketing_profile_id',
        'messages',
        'payload',
    ];

    protected $casts = [
        'messages' => 'array',
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MarketingGroupImportRun::class, 'marketing_group_import_run_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
