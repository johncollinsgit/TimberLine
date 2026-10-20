<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $table = 'marketplace_stock_reservations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
