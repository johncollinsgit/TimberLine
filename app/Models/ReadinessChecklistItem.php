<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One production-readiness checklist item. Platform-global (no tenant scope).
 * status is one of: done | partial | todo.
 */
class ReadinessChecklistItem extends Model
{
    public const STATUS_DONE = 'done';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_TODO = 'todo';

    protected $fillable = [
        'slug',
        'label',
        'category',
        'status',
        'detail',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
