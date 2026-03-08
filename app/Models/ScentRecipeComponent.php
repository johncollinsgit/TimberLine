<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScentRecipeComponent extends Model
{
    use HasFactory;

    public const TYPE_OIL = 'oil';
    public const TYPE_BLEND_TEMPLATE = 'blend_template';

    protected $fillable = [
        'scent_recipe_id',
        'component_type',
        'base_oil_id',
        'blend_template_id',
        'parts',
        'percentage',
        'sort_order',
    ];

    protected $casts = [
        'scent_recipe_id' => 'integer',
        'base_oil_id' => 'integer',
        'blend_template_id' => 'integer',
        'parts' => 'decimal:4',
        'percentage' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function scentRecipe(): BelongsTo
    {
        return $this->belongsTo(ScentRecipe::class, 'scent_recipe_id');
    }

    public function baseOil(): BelongsTo
    {
        return $this->belongsTo(BaseOil::class);
    }

    public function blendTemplate(): BelongsTo
    {
        return $this->belongsTo(BlendTemplate::class, 'blend_template_id');
    }
}
