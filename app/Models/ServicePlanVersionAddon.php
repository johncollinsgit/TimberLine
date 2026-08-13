<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePlanVersionAddon extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'service_plan_version_id', 'field_service_price_book_item_id', 'name', 'description', 'billing_frequency', 'price', 'max_quantity', 'sort_order'];

    protected $casts = ['tenant_id' => 'integer', 'service_plan_version_id' => 'integer', 'field_service_price_book_item_id' => 'integer', 'price' => 'decimal:2', 'max_quantity' => 'integer', 'sort_order' => 'integer'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePlanVersion::class, 'service_plan_version_id');
    }

    public function priceBookItem(): BelongsTo
    {
        return $this->belongsTo(FieldServicePriceBookItem::class, 'field_service_price_book_item_id');
    }
}
