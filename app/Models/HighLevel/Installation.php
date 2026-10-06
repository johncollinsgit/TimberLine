<?php

namespace App\Models\HighLevel;

use Illuminate\Database\Eloquent\Model;

class Installation extends Model
{
    protected $table = 'highlevel_installations';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'installed_at' => 'datetime', 'uninstalled_at' => 'datetime', 'grace_ends_at' => 'datetime', 'lifecycle_at' => 'datetime', 'payment_at' => 'datetime'];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function hasSubscriptionAccess(): bool
    {
        return $this->status === 'installed'
            && filled(config('highlevel.plan_id'))
            && $this->plan_id === config('highlevel.plan_id')
            && ($this->payment_status === 'COMPLETE'
                || ($this->payment_status === 'FAILED' && $this->grace_ends_at?->isFuture()));
    }

    public function collectionAllowed(): bool
    {
        return config('highlevel.enabled') && config('highlevel.billing_verified')
            && config('highlevel.collection_enabled')
            && in_array($this->location_id, config('highlevel.pilot_locations', []), true)
            && $this->hasSubscriptionAccess();
    }
}
