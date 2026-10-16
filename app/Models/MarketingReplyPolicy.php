<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingReplyPolicy extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_reply_policies';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'approved_at' => 'datetime'];
}
