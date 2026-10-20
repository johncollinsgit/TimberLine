<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    protected $table = 'marketplace_operations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'result' => 'encrypted:array'];
    }
}
