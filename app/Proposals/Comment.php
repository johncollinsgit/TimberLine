<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Comment extends Model
{
    use HasUuids;

    protected $table = 'service_proposal_comments';

    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review comments are append-only.'));
        static::deleting(fn () => throw new LogicException('Review comments must be retained.'));
    }
}
