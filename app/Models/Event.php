<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'name','venue','city','state','starts_at','ends_at','due_date','ship_date','status','notes',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'due_date' => 'date',
        'ship_date' => 'date',
    ];

    public function shipments()
    {
        return $this->hasMany(EventShipment::class);
    }
}
