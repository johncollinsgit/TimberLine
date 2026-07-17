<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AgreementVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'content_payload' => 'array',
            'scope_payload' => 'array',
            'pricing_payload' => 'array',
            'subscription_payload' => 'array',
            'termination_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Agreement versions are immutable. Create a new version or amendment.'));
        static::deleting(fn () => throw new RuntimeException('Agreement versions are immutable and cannot be deleted.'));
    }
}
