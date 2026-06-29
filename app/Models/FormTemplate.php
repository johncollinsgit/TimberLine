<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormTemplate extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'visibility',
        'handler_key',
        'schema',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'settings' => 'array',
        ];
    }

    public function tenantForms(): HasMany
    {
        return $this->hasMany(TenantForm::class);
    }
}
