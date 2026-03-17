<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\Shipping\BusinessDayCalculator;
use Carbon\CarbonImmutable;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $appends = ['channel'];

    protected $casts = [
        'ordered_at' => 'datetime',
        'due_at' => 'datetime',
        'ship_by_at' => 'datetime',
        'published_at' => 'datetime',
        'attribution_meta' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function mappingExceptions(): HasMany
    {
        return $this->hasMany(MappingException::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected static function booted(): void
    {
        static::created(function (Order $order): void {
            if ($order->ship_by_at || !$order->created_at) {
                return;
            }

            $calculator = app(BusinessDayCalculator::class);
            $start = CarbonImmutable::parse($order->created_at)->startOfDay();
            $type = $order->order_type ?? $order->channel ?? 'retail';
            $days = $type === 'wholesale' ? 10 : 3;

            $shipBy = $calculator->addBusinessDays($start, $days)->startOfDay();
            $order->ship_by_at = $shipBy;

            if (!$order->due_at) {
                $order->due_at = $calculator->subBusinessDays($shipBy, 2)->startOfDay();
            }

            $order->saveQuietly();
        });
    }

    /**
     * Computed attribute: $order->channel
     * Returns: wholesale | retail | event
     */
    public function getChannelAttribute(): string
    {
        if (!empty($this->order_type)) {
            return (string) $this->order_type;
        }

        $name = strtolower((string) ($this->container_name ?? ''));

        if (str_starts_with($name, 'wholesale:')) {
            return 'wholesale';
        }

        if (str_starts_with($name, 'market:')) {
            // crude-but-effective event detection:
            // tweak these keywords to match your real event naming
            if (
                str_contains($name, 'festival') ||
                str_contains($name, 'show') ||
                str_contains($name, 'fair') ||
                str_contains($name, 'market')
            ) {
                return 'event';
            }

            return 'retail';
        }

        return 'retail';
    }

    /**
     * Display-friendly name for an order.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->order_label
            ?: $this->customer_name
            ?: $this->shipping_name
            ?: $this->billing_name
            ?: $this->shipping_company
            ?: $this->shipping_address1
            ?: $this->billing_company
            ?: $this->billing_address1
            ?: $this->shopify_name
            ?: ($this->order_number ?? '—');
    }

    public function hasOpenMappingExceptions(): bool
    {
        return $this->mappingExceptions()
            ->whereNull('resolved_at')
            ->exists();
    }
}
