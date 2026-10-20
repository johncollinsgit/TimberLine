<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Market extends Model
{
    protected $table = 'marketplace_markets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'pickup_cutoff_at' => 'datetime'];
    }

    public function makers()
    {
        return $this->belongsToMany(Maker::class, 'marketplace_market_makers')->withPivot(['status', 'booth', 'notes'])->withTimestamps();
    }

    public function pickupLocation()
    {
        return $this->belongsTo(PickupLocation::class);
    }
}
