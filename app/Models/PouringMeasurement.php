<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PouringMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'size_code',
        'product_type',
        'wax_grams',
        'oil_grams',
        'total_grams',
        'active',
    ];

    protected $casts = [
        'wax_grams' => 'decimal:2',
        'oil_grams' => 'decimal:2',
        'total_grams' => 'decimal:2',
        'active' => 'boolean',
    ];
}
