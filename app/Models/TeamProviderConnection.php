<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamProviderConnection extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials', 'settings', 'sync_state'];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'credentials' => 'encrypted:array', 'settings' => 'encrypted:array', 'sync_state' => 'encrypted:array', 'expires_at' => 'datetime', 'last_success_at' => 'datetime', 'retry_at' => 'datetime'];
    }
}
