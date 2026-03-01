<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailPlanItem extends Model
{
    use HasFactory;

    public const MARKET_DRAFT_SOURCES = [
        'market_box_draft',
        'market_box_manual',
        'market_box_event_prefill',
        'event_prefill',
        'market_top_shelf_template',
        'market_duration_template',
    ];

    public const MARKET_MERGEABLE_SOURCES = [
        'market_box_manual',
        'market_box_draft',
        'market_box_event_prefill',
        'event_prefill',
        'market_duration_template',
    ];

    protected $fillable = [
        'retail_plan_id',
        'order_id',
        'order_line_id',
        'scent_id',
        'size_id',
        'sku',
        'quantity',
        'inventory_quantity',
        'source',
        'status',
        'upcoming_event_id',
        'box_tier',
        'notes',
    ];

    public function plan()
    {
        return $this->belongsTo(RetailPlan::class, 'retail_plan_id');
    }

    public function scent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Scent::class, 'scent_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Size::class, 'size_id');
    }

    /**
     * @return array<int,string>
     */
    public static function marketDraftSources(): array
    {
        return self::MARKET_DRAFT_SOURCES;
    }

    /**
     * @return array<int,string>
     */
    public static function marketMergeableSources(): array
    {
        return self::MARKET_MERGEABLE_SOURCES;
    }
}
