<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class PickupLocation extends Model
{
    protected $table = 'marketplace_pickup_locations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_address' => 'array', 'active' => 'boolean'];
    }
}
