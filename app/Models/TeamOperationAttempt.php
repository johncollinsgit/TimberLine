<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamOperationAttempt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'result' => 'encrypted:array', 'scheduled_at' => 'datetime'];
    }
}
