<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamTask extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $casts = ['payload' => 'array', 'deleted' => 'boolean', 'version' => 'integer', 'tenant_id' => 'integer'];
}
