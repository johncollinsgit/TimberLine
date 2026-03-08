<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseOil extends Model
{
    use HasFactory;

    protected $table = 'base_oils';

    protected $fillable = [
        'name',
        'grams_on_hand',
        'reorder_threshold',
        'jug_size_grams',
        'supplier',
        'cost_per_jug',
        'active',
    ];

    protected $casts = [
        'grams_on_hand' => 'decimal:2',
        'reorder_threshold' => 'decimal:2',
        'jug_size_grams' => 'decimal:2',
        'cost_per_jug' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function recipeComponents(): HasMany
    {
        return $this->hasMany(ScentRecipeComponent::class);
    }

    public function blendTemplateComponents(): HasMany
    {
        return $this->hasMany(BlendTemplateComponent::class);
    }
}
