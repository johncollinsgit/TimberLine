<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPourList extends Model
{
    protected $fillable = [
        'event_id','title','status','generated_at','published_at','generated_by_user_id','created_by_user_id','published_by_user_id','notes',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'market_pour_list_events');
    }

    public function lines()
    {
        return $this->hasMany(MarketPourListLine::class);
    }
}
