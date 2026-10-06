<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantMailDomain extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'domain', 'transport', 'status', 'provider_domain_id', 'dns_records', 'verified_at'];

    protected $casts = ['dns_records' => 'array', 'verified_at' => 'datetime'];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(TenantMailbox::class);
    }
}
