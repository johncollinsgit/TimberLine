<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $appends = ['channel'];
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * Computed attribute: $order->channel
     * Returns: wholesale | retail | event
     */
    public function getChannelAttribute(): string
    {
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
}
