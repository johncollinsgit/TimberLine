<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Size extends Model
{
    protected $fillable = [
        'code',
        'label',
        'wholesale_price',
        'retail_price',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'wholesale_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
    ];

    public function getDisplayAttribute(): string
    {
        return $this->label ?: $this->code;
    }
}
