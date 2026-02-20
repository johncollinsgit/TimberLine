<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPourListEventLine extends Model
{
    protected $fillable = [
        'market_pour_list_id','event_id','scent_id','size_id','wick_type','recommended_qty','edited_qty',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function scent()
    {
        return $this->belongsTo(Scent::class);
    }

    public function size()
    {
        return $this->belongsTo(Size::class);
    }
}
