<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamProject extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $casts = ['payload' => 'array', 'archived' => 'boolean', 'version' => 'integer', 'tenant_id' => 'integer'];
}
