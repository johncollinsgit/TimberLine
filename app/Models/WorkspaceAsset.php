<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WorkspaceAsset extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'uploaded_by_user_id', 'source', 'external_id', 'visibility', 'storage_disk',
        'storage_path', 'file_name', 'mime_type', 'file_size', 'checksum', 'caption', 'tags',
        'thumbnail_disk', 'thumbnail_path',
        'search_text', 'metadata', 'captured_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'file_size' => 'integer',
        'tags' => 'array',
        'metadata' => 'array',
        'captured_at' => 'datetime',
    ];

    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(FieldServiceJob::class, 'field_service_job_workspace_asset')
            ->withPivot(['tenant_id', 'linked_by_user_id'])
            ->withTimestamps();
    }

    public function financialDocuments(): BelongsToMany
    {
        return $this->belongsToMany(FieldServiceFinancialDocument::class, 'financial_document_workspace_asset')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
