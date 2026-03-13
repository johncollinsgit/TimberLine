<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingGroupImportRun extends Model
{
    protected $fillable = [
        'marketing_group_id',
        'file_name',
        'status',
        'started_at',
        'finished_at',
        'summary',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(MarketingGroup::class, 'marketing_group_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(MarketingGroupImportRow::class, 'marketing_group_import_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
