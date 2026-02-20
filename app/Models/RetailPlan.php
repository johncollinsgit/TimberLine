<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RetailPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(RetailPlanItem::class);
    }
}
