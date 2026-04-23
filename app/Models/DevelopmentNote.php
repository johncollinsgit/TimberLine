<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevelopmentNote extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'title',
        'body',
        'created_by',
        'updated_by',
        'shopify_admin_user_id',
        'shopify_admin_email',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
