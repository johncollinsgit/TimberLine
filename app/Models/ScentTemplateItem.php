<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScentTemplateItem extends Model
{
    protected $fillable = [
        'template_id',
        'scent_id',
        'sort_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ScentTemplate::class, 'template_id');
    }

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }
}
