<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioBrief extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['social_clip' => 'boolean', 'proof' => 'array'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(StudioOffer::class, 'studio_offer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WebsiteOrder::class, 'website_order_id');
    }
}
