<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingImportRun extends Model
{
    protected $fillable = [
        'type',
        'status',
        'source_label',
        'file_name',
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

    public function rows(): HasMany
    {
        return $this->hasMany(MarketingImportRow::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
