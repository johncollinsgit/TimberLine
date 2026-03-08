<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    use HasFactory;

    public const ITEM_TYPE_OIL = 'oil';
    public const ITEM_TYPE_WAX = 'wax';

    public const REASON_SPILL = 'spill';
    public const REASON_DAMAGE = 'damage';
    public const REASON_RECOUNT = 'recount';
    public const REASON_MANUAL_CORRECTION = 'manual_correction';
    public const REASON_RECEIVED = 'received';
    public const REASON_OTHER = 'other';
    public const REASON_CONSUMED = 'consumed';

    protected $fillable = [
        'item_type',
        'base_oil_id',
        'wax_inventory_id',
        'grams_delta',
        'before_grams',
        'after_grams',
        'reason',
        'notes',
        'performed_by',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'base_oil_id' => 'integer',
        'wax_inventory_id' => 'integer',
        'grams_delta' => 'decimal:2',
        'before_grams' => 'decimal:2',
        'after_grams' => 'decimal:2',
        'performed_by' => 'integer',
        'source_id' => 'integer',
    ];

    /**
     * @return array<int,string>
     */
    public static function reasons(): array
    {
        return [
            self::REASON_SPILL,
            self::REASON_DAMAGE,
            self::REASON_RECOUNT,
            self::REASON_MANUAL_CORRECTION,
            self::REASON_RECEIVED,
            self::REASON_OTHER,
            self::REASON_CONSUMED,
        ];
    }

    public function baseOil(): BelongsTo
    {
        return $this->belongsTo(BaseOil::class);
    }

    public function waxInventory(): BelongsTo
    {
        return $this->belongsTo(WaxInventory::class);
    }
}
