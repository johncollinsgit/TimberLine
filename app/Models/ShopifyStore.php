<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShopifyStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_key',
        'shop_domain',
        'access_token',
        'scopes',
        'installed_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
        'access_token' => 'encrypted',
    ];
}
