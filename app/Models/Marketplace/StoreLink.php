<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class StoreLink extends Model
{
    protected $table = 'marketplace_store_links';

    protected $guarded = [];

    public function maker()
    {
        return $this->belongsTo(Maker::class);
    }
}
