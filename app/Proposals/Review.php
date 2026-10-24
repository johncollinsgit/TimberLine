<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Review extends Model
{
    use HasUuids;

    protected $table = 'service_proposal_reviews';

    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review requests are immutable evidence.'));
        static::deleting(fn () => throw new LogicException('Review evidence must be retained.'));
    }
}
