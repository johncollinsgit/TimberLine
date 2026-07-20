<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agreement extends Model
{
    use HasTenantScope;

    public const TYPE_FRONT_YARD_CLIENT_SERVICES = 'launch_partner';

    public const TYPE_SANDBOX_VALIDATION = 'sandbox_validation';

    public const TEMPLATE_FRONT_YARD_CLIENT_SERVICES = 'front_yard_foods_launch_partner';

    public const TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES = 'collins_electric_launch_partner';

    public const TEMPLATE_FRONT_YARD_SANDBOX_VALIDATION = 'front_yard_foods_sandbox_validation';

    public const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired', 'active', 'termination_pending', 'terminated'];

    protected $guarded = [];

    protected $hidden = ['public_token_hash', 'public_token_encrypted', 'password_hash', 'internal_notes'];

    protected function casts(): array
    {
        return [
            'public_token_encrypted' => 'encrypted',
            'access_expires_at' => 'datetime',
            'access_revoked_at' => 'datetime',
            'sent_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'sms_sent_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'effective_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parentAgreement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agreement_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgreementVersion::class);
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(AgreementAcceptance::class);
    }

    public function acceptance(): HasOne
    {
        return $this->hasOne(AgreementAcceptance::class)->latestOfMany();
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgreementEvent::class);
    }

    public function termination(): HasOne
    {
        return $this->hasOne(AgreementTermination::class)->latestOfMany();
    }

    public function billingOrders(): HasMany
    {
        return $this->hasMany(TenantBillingOrder::class);
    }
}
