<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLineScentSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_line_id',
        'mapping_exception_id',
        'scent_id',
        'raw_scent_name',
        'quantity',
        'allocation_type',
        'notes',
        'created_by',
    ];

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function mappingException(): BelongsTo
    {
        return $this->belongsTo(MappingException::class);
    }

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }
}

