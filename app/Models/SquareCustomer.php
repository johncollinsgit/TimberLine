<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SquareCustomer extends Model
{
    protected $fillable = [
        'square_customer_id',
        'given_name',
        'family_name',
        'email',
        'phone',
        'reference_id',
        'group_ids',
        'segment_ids',
        'preferences',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'group_ids' => 'array',
        'segment_ids' => 'array',
        'preferences' => 'array',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(SquareOrder::class, 'square_customer_id', 'square_customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SquarePayment::class, 'square_customer_id', 'square_customer_id');
    }
}
