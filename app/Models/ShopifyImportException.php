<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyImportException extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'shop',
        'shopify_order_id',
        'shopify_line_item_id',
        'title',
        'reason',
        'payload',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'payload' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
