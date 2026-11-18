<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $table = 'trajectory_push_subscriptions';

    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'keys', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'keys' => 'encrypted:array', 'disabled_at' => 'immutable_datetime'];
    }
}
