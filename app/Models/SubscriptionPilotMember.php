<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionPilotMember extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['details'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'marketing_profile_id' => 'integer', 'shopify_store_id' => 'integer',
            'expected_amount_cents' => 'integer', 'target_date' => 'immutable_date', 'details' => 'encrypted:array'];
    }
}
