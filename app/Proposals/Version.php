<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Version extends Model
{
    use HasUuids;

    protected $table = 'service_proposal_versions';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'password_hash', 'payload', 'access_result', 'publish_key'];

    protected $casts = ['payload' => 'encrypted:array', 'access_result' => 'encrypted:array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (array_diff(array_keys($version->getDirty()), ['revoked_at', 'updated_at'])) {
                throw new LogicException('Published proposal content and access settings are immutable. Publish a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Published proposal evidence must be retained.'));
    }
}
