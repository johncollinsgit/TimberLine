<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'trajectory_transactions';

    protected $guarded = ['id'];

    protected $hidden = ['source'];

    protected function casts(): array
    {
        return ['merchant' => 'encrypted', 'source' => 'encrypted:array', 'amount_cents' => 'integer', 'reviewed' => 'boolean', 'face_punched' => 'boolean', 'bullshit_spending' => 'boolean', 'pending' => 'boolean', 'removed' => 'boolean', 'posted_on' => 'immutable_date'];
    }
}
