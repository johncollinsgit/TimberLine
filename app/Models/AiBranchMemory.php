<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiBranchMemory extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $casts = ['tenant_id' => 'integer', 'body' => 'encrypted', 'expires_at' => 'datetime'];
}
