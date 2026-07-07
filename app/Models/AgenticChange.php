<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single landlord/operator-visible autonomous change to the platform.
 * Platform-global (no tenant scope) — see the agentic_changes migration.
 */
class AgenticChange extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'summary',
        'category',
        'status',
        'impact',
        'reference',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'immutable_datetime',
    ];
}
