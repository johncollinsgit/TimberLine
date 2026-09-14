<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    protected $table = 'trajectory_records';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'active' => 'boolean'];
    }
}
