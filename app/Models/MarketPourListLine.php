<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPourListLine extends Model
{
    protected $fillable = [
        'market_pour_list_id','scent_id','size_id','wick_type','recommended_qty','edited_qty','reason_json',
    ];

    protected $casts = [
        'reason_json' => 'array',
    ];

    public function list()
    {
        return $this->belongsTo(MarketPourList::class, 'market_pour_list_id');
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
