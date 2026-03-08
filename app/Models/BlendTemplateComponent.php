<?php

namespace App\Models;

class BlendTemplateComponent extends BlendComponent
{
    protected $table = 'blend_components';

    protected $fillable = [
        'blend_id',
        'component_type',
        'base_oil_id',
        'blend_template_id',
        'ratio_weight',
        'percentage',
        'sort_order',
    ];
}
