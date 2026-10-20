<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Checkout extends Model
{
    protected $table = 'marketplace_checkouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'provider_cart' => 'encrypted:array', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function connection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Order::class, 'checkout_id');
    }
}
