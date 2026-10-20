<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    protected $table = 'marketplace_connections';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'test_mode' => 'boolean', 'verified_at' => 'datetime'];
    }
}
