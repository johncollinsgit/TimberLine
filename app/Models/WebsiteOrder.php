<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteOrder extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    /** Practice orders are available only through the separately gated POS lane. */
    public function scopeWithoutPosSimulation(Builder $query): Builder
    {
        $source = $query->getModel()->qualifyColumn('source');

        return $query->where(fn (Builder $query) => $query->whereNull($source)->orWhere($source, '!=', 'pos_simulation'));
    }

    public function assertNotPosSimulation(): void
    {
        abort_if($this->source === 'pos_simulation', 404);
        // A supplied/stale model must not bypass the persisted source boundary.
        // Shared reads see current values inside retained MySQL RR snapshots.
        $query = static::query()->forTenantId($this->tenant_id)->whereKey($this->getKey());
        if ($this->getConnection()->transactionLevel() > 0) {
            $query->sharedLock();
        }
        $stored = $query->firstOrFail(['id', 'source']);
        abort_if($stored->source === 'pos_simulation', 404);
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'website_customer_id' => 'integer', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'tax_cents' => 'integer', 'shipping_cents' => 'integer', 'refunded_cents' => 'integer', 'total_cents' => 'integer', 'customer_snapshot' => 'encrypted:array', 'shipping_address' => 'encrypted:array', 'billing_address' => 'encrypted:array', 'shipping_rate_snapshot' => 'array', 'service_request' => 'encrypted:array', 'paid_at' => 'datetime', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WebsiteOrderLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(WebsitePayment::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(WebsiteFulfillment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(WebsiteShipment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebsiteOrderEvent::class)->latest();
    }
}
