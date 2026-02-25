<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Event extends Model
{
    protected $fillable = [
        'market_id','year','name','display_name','venue','city','state','starts_at','ends_at','due_date','ship_date','status','notes','source','source_ref','parse_confidence','parse_notes_json','needs_review',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'due_date' => 'date',
        'ship_date' => 'date',
        'parse_notes_json' => 'array',
        'needs_review' => 'boolean',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(EventShipment::class);
    }

    public function boxShipments(): HasMany
    {
        return $this->hasMany(MarketBoxShipment::class);
    }

    public function marketPourList(): HasOne
    {
        return $this->hasOne(MarketPourList::class);
    }

    public function marketPourLists(): BelongsToMany
    {
        return $this->belongsToMany(MarketPourList::class, 'market_pour_list_events');
    }
}
