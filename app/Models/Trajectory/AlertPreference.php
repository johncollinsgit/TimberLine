<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class AlertPreference extends Model
{
    protected $table = 'trajectory_alert_preferences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'include_pending' => 'boolean', 'show_details' => 'boolean', 'enabled_at' => 'immutable_datetime'];
    }
}
