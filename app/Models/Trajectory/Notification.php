<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'trajectory_notifications';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'body' => 'encrypted', 'provider_id' => 'encrypted', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
