<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasTenantScope;

class ShopifyStore extends Model
{
    use HasFactory;
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'shop_domain',
        'access_token',
        'scopes',
        'storefront_widget_settings',
        'installed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'installed_at' => 'datetime',
        'access_token' => 'encrypted',
        'storefront_widget_settings' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function integrationHealthEvents(): HasMany
    {
        return $this->hasMany(IntegrationHealthEvent::class);
    }
}
