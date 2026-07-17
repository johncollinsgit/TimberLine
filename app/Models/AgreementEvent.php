<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AgreementEvent extends Model
{
    use HasTenantScope;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'agreement_version_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Agreement events are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Agreement events are append-only.'));
    }
}
