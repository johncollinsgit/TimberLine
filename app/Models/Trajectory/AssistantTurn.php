<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class AssistantTurn extends Model
{
    protected $table = 'trajectory_assistant_turns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['question' => 'encrypted', 'context' => 'encrypted:array', 'response' => 'encrypted:array'];
    }
}
