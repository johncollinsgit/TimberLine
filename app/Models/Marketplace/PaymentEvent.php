<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $table = 'marketplace_payment_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'processed_at' => 'datetime'];
    }
}
