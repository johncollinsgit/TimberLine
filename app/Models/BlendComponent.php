<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BlendComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'blend_id',
        'base_oil_id',
        'ratio_weight',
    ];

    public function blend()
    {
        return $this->belongsTo(Blend::class);
    }

    public function baseOil()
    {
        return $this->belongsTo(BaseOil::class);
    }
}
