<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamOperationRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'data' => 'encrypted:array', 'starts_on' => 'date', 'ends_on' => 'date'];
    }
}
