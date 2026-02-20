<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
    ];

    public function plan()
    {
        return $this->belongsTo(RetailPlan::class, 'retail_plan_id');
    }
}
