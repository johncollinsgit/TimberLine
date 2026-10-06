<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A read-only external commerce lane. It is intentionally separate from native
 * Website Commerce and from the pre-existing Shopify integration tables.
 */
class CommerceSource extends Model
{
    use BelongsToTenant;

    public const PROVIDERS = ['shopify', 'woocommerce', 'squarespace', 'wix'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'integration_connection_id' => 'integer',
            'capabilities' => 'array',
            'metadata' => 'array',
            'last_imported_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(CommerceImportRun::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(CommerceExternalRecord::class);
    }
}
