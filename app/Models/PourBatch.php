<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PourBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'selection_mode',
        'order_type',
        'wax_total_grams',
        'oil_total_grams',
        'alcohol_total_grams',
        'water_total_grams',
        'total_grams',
        'pitcher_count',
        'created_by',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'wax_total_grams' => 'decimal:2',
        'oil_total_grams' => 'decimal:2',
        'alcohol_total_grams' => 'decimal:2',
        'water_total_grams' => 'decimal:2',
        'total_grams' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(PourBatchLine::class);
    }

    public function pitchers()
    {
        return $this->hasMany(PourBatchPitcher::class);
    }
}
