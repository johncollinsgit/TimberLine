<?php

namespace App\Models\HighLevel;

use Illuminate\Database\Eloquent\Model;

class UserBinding extends Model
{
    protected $table = 'highlevel_user_bindings';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected $casts = ['verified_at' => 'datetime', 'revoked_at' => 'datetime'];
}
