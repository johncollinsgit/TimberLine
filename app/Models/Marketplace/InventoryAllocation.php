<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class InventoryAllocation extends Model
{
    protected $table = 'marketplace_inventory_allocations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'applied_at' => 'datetime'];
    }
}
