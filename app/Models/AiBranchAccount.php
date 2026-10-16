<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiBranchAccount extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $casts = ['tenant_id' => 'integer', 'enabled' => 'boolean', 'budget_cents' => 'integer', 'approved_at' => 'datetime'];
}
