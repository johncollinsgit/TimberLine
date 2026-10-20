<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'marketplace_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['readiness' => 'array', 'checkout_enabled' => 'boolean'];
    }
}
