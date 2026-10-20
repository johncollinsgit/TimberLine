<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'marketplace_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fulfillment_details' => 'encrypted:array', 'customer' => 'encrypted:array', 'review_required' => 'boolean', 'test_mode' => 'boolean', 'shipping_allocated_at' => 'datetime', 'shipping_refunds' => 'array'];
    }

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id');
    }

    public function checkout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Checkout::class, 'checkout_id');
    }

    public function pickupDetails(): array
    {
        if (! empty($this->fulfillment_details['pickup'])) {
            return $this->fulfillment_details['pickup'];
        }
        if (! empty($this->checkout->snapshot['pickup'])) {
            return $this->checkout->snapshot['pickup'];
        }
        $location = PickupLocation::find((int) substr($this->group_key, 7));

        return ['name' => $location?->name, 'address' => $location?->address, 'instructions' => $location?->instructions];
    }
}
