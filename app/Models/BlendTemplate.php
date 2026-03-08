<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class BlendTemplate extends Blend
{
    protected $table = 'blends';

    public function templateComponents(): HasMany
    {
        return $this->hasMany(BlendTemplateComponent::class, 'blend_id')->orderBy('sort_order');
    }

    public function childTemplateComponents(): HasMany
    {
        return $this->hasMany(BlendTemplateComponent::class, 'blend_template_id');
    }
}
