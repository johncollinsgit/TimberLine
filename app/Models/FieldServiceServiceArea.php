<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldServiceServiceArea extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'name', 'postal_prefixes', 'active'];

    protected $casts = ['tenant_id' => 'integer', 'postal_prefixes' => 'array', 'active' => 'boolean'];
}
