<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class ExpenseNotice extends Model
{
    protected $table = 'trajectory_expense_notices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'read_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
