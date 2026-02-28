<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailPlanItem extends Model
{
    use HasFactory;

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
}
