<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function shopifyStores(): HasMany
    {
        return $this->hasMany(ShopifyStore::class);
    }

    public function marketingProfiles(): HasMany
    {
        return $this->hasMany(MarketingProfile::class);
    }

    public function marketingProfileLinks(): HasMany
    {
        return $this->hasMany(MarketingProfileLink::class);
    }

    public function marketingConsentRequests(): HasMany
    {
        return $this->hasMany(MarketingConsentRequest::class);
    }

    public function marketingConsentEvents(): HasMany
    {
        return $this->hasMany(MarketingConsentEvent::class);
    }

    public function customerExternalProfiles(): HasMany
    {
        return $this->hasMany(CustomerExternalProfile::class);
    }

    public function marketingStorefrontEvents(): HasMany
    {
        return $this->hasMany(MarketingStorefrontEvent::class);
    }

    public function integrationHealthEvents(): HasMany
    {
        return $this->hasMany(IntegrationHealthEvent::class);
    }

    public function marketingAutomationEvents(): HasMany
    {
        return $this->hasMany(MarketingAutomationEvent::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
