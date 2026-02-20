<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventShipment extends Model
{
    protected $fillable = [
        'event_id','scent_id','size_id','wick_type','planned_qty','sent_qty','returned_qty','sold_qty',
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
