<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingBrandKnowledgeVersion extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_brand_knowledge_versions';

    protected $guarded = [];

    protected $hidden = ['content'];

    protected $casts = ['content' => 'encrypted:array', 'approved_at' => 'datetime', 'expires_at' => 'datetime'];
}
