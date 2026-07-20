<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBrandAsset extends Model
{
    protected $fillable = [
        'tenant_id', 'tenant_brand_profile_id', 'uploaded_by_user_id', 'kind', 'label', 'source',
        'storage_disk', 'path', 'mime_type', 'file_size', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'tenant_brand_profile_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'file_size' => 'integer',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TenantBrandProfile::class, 'tenant_brand_profile_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function publicUrl(): string
    {
        return $this->source === 'upload'
            ? \Illuminate\Support\Facades\Storage::disk($this->storage_disk ?: 'public')->url($this->path)
            : asset(ltrim((string) $this->path, '/'));
    }
}
