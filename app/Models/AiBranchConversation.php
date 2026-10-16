<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiBranchConversation extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $casts = ['tenant_id' => 'integer', 'messages' => 'encrypted:array', 'expires_at' => 'datetime'];
}
