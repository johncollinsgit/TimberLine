<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCount extends Model
{
    protected $fillable = [
        'scent_id',
        'size_id',
        'on_hand_qty',
    ];
}
