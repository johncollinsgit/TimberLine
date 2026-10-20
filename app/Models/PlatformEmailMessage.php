<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformEmailMessage extends Model
{
    protected $guarded = [];

    protected $hidden = ['mime', 'payload_hash'];

    protected $casts = ['mime' => 'encrypted', 'tenant_id' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rendered platform email intent is immutable.'));
        static::deleting(fn () => throw new LogicException('Platform email intent must be retained.'));
    }
}
