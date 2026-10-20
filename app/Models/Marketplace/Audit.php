<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    protected $table = 'marketplace_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
