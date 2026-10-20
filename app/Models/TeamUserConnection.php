<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamUserConnection extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'project_ids' => 'array', 'preferences' => 'array', 'expires_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }
}
