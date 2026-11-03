<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionMemberVote extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['evidence', 'marketing_profile_id', 'request_uuid'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'member_id' => 'integer', 'agreement_id' => 'integer', 'subscription_poll_id' => 'integer',
            'subscription_poll_option_id' => 'integer', 'marketing_profile_id' => 'integer', 'agreement_revision' => 'integer', 'evidence' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::uuid());
        static::updating(fn () => throw new \LogicException('Retain the originally accepted Candle Club ballot.'));
        static::deleting(fn () => throw new \LogicException('Retain Candle Club voting history.'));
    }
}
