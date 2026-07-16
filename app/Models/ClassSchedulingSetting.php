<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSchedulingSetting extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'public_signup_enabled', 'timezone', 'public_heading', 'public_intro',
        'contact_email', 'logo_url', 'hero_image_url', 'brand_color', 'default_reminder_offsets', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'public_signup_enabled' => 'boolean',
        'default_reminder_offsets' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
