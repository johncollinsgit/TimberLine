<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AgreementAcceptance extends Model
{
    use HasTenantScope;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['electronic_signature_value', 'ip_address', 'user_agent', 'evidence_hash'];

    protected function casts(): array
    {
        return [
            'electronic_signature_value' => 'encrypted',
            'ip_address' => 'encrypted',
            'user_agent' => 'encrypted',
            'authorized_to_bind' => 'boolean',
            'accepted_scope' => 'boolean',
            'accepted_pricing' => 'boolean',
            'accepted_subscription' => 'boolean',
            'accepted_hourly_rate' => 'boolean',
            'accepted_termination' => 'boolean',
            'electronic_consent' => 'boolean',
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
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

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Agreement acceptances are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Agreement acceptances cannot be deleted.'));
    }
}
