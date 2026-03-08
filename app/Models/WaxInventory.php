<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaxInventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'on_hand_grams',
        'reorder_threshold_grams',
        'active',
        'notes',
    ];

    protected $casts = [
        'on_hand_grams' => 'decimal:2',
        'reorder_threshold_grams' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }
}
