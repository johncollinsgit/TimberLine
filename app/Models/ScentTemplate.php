<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScentTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ScentTemplateItem::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scents(): BelongsToMany
    {
        return $this->belongsToMany(Scent::class, 'scent_template_items', 'template_id', 'scent_id')
            ->withPivot('sort_order')
            ->orderBy('scent_template_items.sort_order');
    }
}
