<?php

namespace App\Models\HighLevel;

use Illuminate\Database\Eloquent\Model;

class Authorization extends Model
{
    protected $table = 'highlevel_authorizations';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime'];
}
