<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WorkspaceAssetUpload extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'uploaded_by_user_id', 'field_service_job_id', 'token_hash', 'storage_disk', 'storage_path', 'file_name', 'mime_type', 'max_file_size', 'visibility', 'caption', 'status', 'expires_at', 'completed_at'];

    protected $hidden = ['token_hash'];

    protected $casts = ['tenant_id' => 'integer', 'uploaded_by_user_id' => 'integer', 'field_service_job_id' => 'integer', 'max_file_size' => 'integer', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
}
