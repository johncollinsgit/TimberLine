<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'trajectory_events';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['before' => 'encrypted:array', 'after' => 'encrypted:array'];
    }
}
