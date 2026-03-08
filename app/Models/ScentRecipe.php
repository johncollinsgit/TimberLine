<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScentRecipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'scent_id',
        'version',
        'status',
        'is_active',
        'activated_at',
        'notes',
        'source_context',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ScentRecipeComponent::class, 'scent_recipe_id')->orderBy('sort_order');
    }
}
