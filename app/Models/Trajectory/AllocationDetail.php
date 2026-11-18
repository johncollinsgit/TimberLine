<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class AllocationDetail extends Model
{
    protected $table = 'trajectory_allocation_details';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'revision' => 'integer', 'line_index' => 'integer',
            'settlement_note' => 'encrypted', 'active' => 'boolean', 'invalidated_at' => 'immutable_datetime'];
    }
}
