<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class OrderLine extends Model
{
    protected $table = 'marketplace_order_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fulfilled_at' => 'datetime'];
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function maker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Maker::class, 'maker_id');
    }
}
