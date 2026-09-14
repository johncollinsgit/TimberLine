<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Space extends Model
{
    protected $table = 'trajectory_spaces';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array', 'enabled' => 'boolean'];
    }
}
