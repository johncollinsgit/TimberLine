<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportNormalization extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'shopify_order_id',
        'shopify_line_item_id',
        'order_id',
        'field',
        'raw_value',
        'normalized_value',
        'context_json',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'context_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
