<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteStripeWebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'payload' => 'array', 'processed_at' => 'datetime'];
    }
}
