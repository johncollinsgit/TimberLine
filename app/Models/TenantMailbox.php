<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantMailbox extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'tenant_mail_domain_id', 'address', 'display_name', 'status', 'provider_account_id', 'provider_credentials', 'last_synced_at'];

    protected $hidden = ['provider_credentials'];

    protected $casts = ['provider_credentials' => 'encrypted:array', 'last_synced_at' => 'datetime'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(TenantMailDomain::class, 'tenant_mail_domain_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_mailbox_users')->withPivot(['tenant_id', 'permission'])->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantMailMessage::class);
    }
}
