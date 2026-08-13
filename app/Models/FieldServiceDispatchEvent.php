<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldServiceDispatchEvent extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'field_service_job_id', 'actor_user_id', 'event_type', 'before', 'after', 'explanation'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_job_id' => 'integer', 'actor_user_id' => 'integer', 'before' => 'array', 'after' => 'array', 'explanation' => 'array'];
}
