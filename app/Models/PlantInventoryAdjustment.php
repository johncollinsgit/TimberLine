<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantInventoryAdjustment extends Model
{
    use HasTenantScope;

    public const TYPE_RECEIVED = 'received';

    public const TYPE_SOLD = 'sold';

    public const TYPE_HELD = 'held';

    public const TYPE_RELEASED = 'released';

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_CORRECTION = 'correction';

    protected $table = 'tenant_plant_inventory_adjustments';

    protected $fillable = [
        'tenant_id',
        'plant_inventory_item_id',
        'performed_by_user_id',
        'adjustment_type',
        'quantity_delta',
        'reserved_delta',
        'before_quantity_on_hand',
        'after_quantity_on_hand',
        'before_reserved_quantity',
        'after_reserved_quantity',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'plant_inventory_item_id' => 'integer',
        'performed_by_user_id' => 'integer',
        'quantity_delta' => 'integer',
        'reserved_delta' => 'integer',
        'before_quantity_on_hand' => 'integer',
        'after_quantity_on_hand' => 'integer',
        'before_reserved_quantity' => 'integer',
        'after_reserved_quantity' => 'integer',
        'metadata' => 'array',
    ];

    /** @return array<int,string> */
    public static function types(): array
    {
        return [
            self::TYPE_RECEIVED,
            self::TYPE_SOLD,
            self::TYPE_HELD,
            self::TYPE_RELEASED,
            self::TYPE_DAMAGED,
            self::TYPE_CORRECTION,
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlantInventoryItem::class, 'plant_inventory_item_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
