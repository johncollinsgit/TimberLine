<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantForm extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'form_template_id',
        'slug',
        'name',
        'description',
        'status',
        'channel',
        'schema',
        'destination',
        'settings',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'form_template_id' => 'integer',
            'schema' => 'array',
            'destination' => 'array',
            'settings' => 'array',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
