<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'trajectory_accounts';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer', 'observed_at' => 'immutable_datetime', 'history_start' => 'immutable_date'];
    }
}
