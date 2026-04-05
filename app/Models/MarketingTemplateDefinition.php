<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingTemplateDefinition extends Model
{
    protected $fillable = [
        'template_key',
        'channel',
        'name',
        'description',
        'thumbnail_svg',
        'default_subject',
        'default_sections',
        'is_active',
    ];

    protected $casts = [
        'default_sections' => 'array',
        'is_active' => 'boolean',
    ];

    public function instances(): HasMany
    {
        return $this->hasMany(MarketingTemplateInstance::class, 'template_definition_id');
    }
}
