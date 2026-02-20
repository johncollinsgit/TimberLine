<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RoomSprayMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'quantity',
        'alcohol_grams',
        'oil_grams',
        'water_grams',
        'total_grams',
        'active',
    ];

    protected $casts = [
        'alcohol_grams' => 'decimal:2',
        'oil_grams' => 'decimal:2',
        'water_grams' => 'decimal:2',
        'total_grams' => 'decimal:2',
        'active' => 'boolean',
    ];
}
