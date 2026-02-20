<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PourRequestLine extends Model
{
    protected $fillable = [
        'pour_request_id','scent_id','size_id','wick_type','qty','produced_qty',
    ];

    public function scent()
    {
        return $this->belongsTo(Scent::class);
    }

    public function size()
    {
        return $this->belongsTo(Size::class);
    }
}
