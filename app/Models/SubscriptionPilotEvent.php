<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPilotEvent extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['evidence'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Pilot observations are append-only.'));
        static::deleting(fn () => throw new \LogicException('Pilot observations must be retained.'));
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'member_id' => 'integer', 'amount_cents' => 'integer', 'cycle_date' => 'immutable_date',
            'occurred_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime', 'evidence' => 'encrypted:array'];
    }
}
