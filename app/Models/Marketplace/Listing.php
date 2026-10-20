<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    protected $table = 'marketplace_listings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['images' => 'array', 'pickup_location_ids' => 'array', 'shipping' => 'boolean', 'price_cents' => 'integer', 'stock_quantity' => 'integer', 'revision' => 'integer', 'published_revision' => 'integer', 'synced_at' => 'datetime'];
    }

    public function maker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Maker::class, 'maker_id');
    }

    public function levels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InventoryLevel::class, 'listing_id');
    }
}
