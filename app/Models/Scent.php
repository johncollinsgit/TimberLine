<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scent extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
