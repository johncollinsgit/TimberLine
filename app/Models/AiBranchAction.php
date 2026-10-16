<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiBranchAction extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $casts = ['tenant_id' => 'integer', 'payload' => 'encrypted:array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
}
