<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingSocialPost extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_social_posts';

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected $casts = ['content' => 'encrypted:array', 'scheduled_at' => 'datetime', 'approved_at' => 'datetime'];
}
