<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OilMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'base_oil_id',
        'grams',
        'reason',
        'source_type',
        'source_id',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'grams' => 'decimal:2',
    ];

    public function baseOil()
    {
        return $this->belongsTo(BaseOil::class);
    }
}
