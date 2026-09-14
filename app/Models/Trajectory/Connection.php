<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    protected $table = 'trajectory_connections';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'cursor', 'coverage'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'cursor' => 'encrypted', 'coverage' => 'encrypted:array', 'synced_at' => 'immutable_datetime'];
    }
}
