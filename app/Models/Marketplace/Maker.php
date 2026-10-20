<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Maker extends Model
{
    protected $table = 'marketplace_makers';

    protected $guarded = [];

    protected $hidden = ['private_details'];

    protected function casts(): array
    {
        return ['private_details' => 'encrypted:array', 'approved_at' => 'datetime'];
    }

    public function listings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Listing::class, 'maker_id');
    }

    public function memberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Membership::class, 'maker_id');
    }
}
