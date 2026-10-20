<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class LoginLink extends Model
{
    protected $table = 'marketplace_login_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
