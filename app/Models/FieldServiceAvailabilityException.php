<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldServiceAvailabilityException extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'user_id', 'starts_at', 'ends_at', 'reason'];

    protected $casts = ['tenant_id' => 'integer', 'user_id' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
