<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPourList extends Model
{
    protected $fillable = [
        'title','status','generated_at','generated_by_user_id','notes',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function events()
    {
        return $this->belongsToMany(Event::class, 'market_pour_list_events');
    }

    public function lines()
    {
        return $this->hasMany(MarketPourListLine::class);
    }
}
