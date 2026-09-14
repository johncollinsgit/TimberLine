<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Allocation extends Model
{
    protected $table = 'trajectory_allocations';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
