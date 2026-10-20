<?php

namespace App\Models\Marketplace;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $table = 'marketplace_stores';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'settings' => 'array'];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
