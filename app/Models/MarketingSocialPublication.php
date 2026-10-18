<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingSocialPublication extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_social_publications';

    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected $casts = ['insights' => 'array', 'observed_at' => 'datetime'];
}
