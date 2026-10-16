<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingAudiencePermission extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_audience_permissions';

    protected $guarded = [];

    protected $casts = ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
}
