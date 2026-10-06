<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Snapshot extends Model
{
    protected $table = 'trajectory_snapshots';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'observed_on' => 'immutable_date'];
    }
}
