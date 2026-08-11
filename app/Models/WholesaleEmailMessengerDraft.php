<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WholesaleEmailMessengerDraft extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'store_key', 'name', 'subject', 'sections', 'personalization',
        'revision', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'sections' => 'array',
        'personalization' => 'array',
        'revision' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
