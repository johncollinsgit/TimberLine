<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PourBatchPitcher extends Model
{
    use HasFactory;

    protected $fillable = [
        'pour_batch_id',
        'pitcher_index',
        'wax_grams',
        'oil_grams',
        'total_grams',
    ];

    protected $casts = [
        'wax_grams' => 'decimal:2',
        'oil_grams' => 'decimal:2',
        'total_grams' => 'decimal:2',
    ];

    public function batch()
    {
        return $this->belongsTo(PourBatch::class, 'pour_batch_id');
    }
}
