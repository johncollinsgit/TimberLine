<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandlordCatalogEntry extends Model
{
    public const TYPE_PLAN = 'plan';

    public const TYPE_ADDON = 'addon';

    public const TYPE_TEMPLATE = 'template';

    public const TYPE_SETUP_PACKAGE = 'setup_package';

    protected $fillable = [
        'entry_type',
        'entry_key',
        'name',
        'status',
        'is_active',
        'is_public',
        'position',
        'currency',
        'recurring_price_cents',
        'recurring_interval',
        'setup_price_cents',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'position' => 'integer',
        'recurring_price_cents' => 'integer',
        'setup_price_cents' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'payload' => 'array',
    ];
}
