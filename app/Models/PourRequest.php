<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PourRequest extends Model
{
    protected $fillable = [
        'source_type','source_id','status','due_date','notes',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function lines()
    {
        return $this->hasMany(PourRequestLine::class);
    }
}
