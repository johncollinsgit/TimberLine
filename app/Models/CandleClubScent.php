<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleClubScent extends Model
{
    use HasFactory;

    protected $fillable = [
        'month',
        'year',
        'scent_id',
    ];

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }
}
