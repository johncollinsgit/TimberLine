<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSiteMedia extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'tenant_site_id', 'uploaded_by_user_id', 'storage_disk', 'storage_path', 'file_name',
        'mime_type', 'file_size', 'checksum', 'kind', 'source', 'source_url', 'alt_text', 'is_starter',
    ];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'uploaded_by_user_id' => 'integer', 'file_size' => 'integer', 'is_starter' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }
}
