<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Invite extends Model
{
    protected $table = 'trajectory_invites';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }
}
