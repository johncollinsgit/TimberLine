<?php

namespace App\Models\HighLevel;

use Illuminate\Database\Eloquent\Model;

class OAuthState extends Model
{
    protected $table = 'highlevel_oauth_states';

    protected $guarded = ['id'];

    protected $hidden = ['state_hash', 'payload'];

    protected $casts = ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
