<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldMaterialCatalogItem extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'name', 'sku', 'unit', 'description', 'active'];

    protected $casts = ['tenant_id' => 'integer', 'active' => 'boolean'];
}
