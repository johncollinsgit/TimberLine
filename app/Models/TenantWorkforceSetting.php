<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class TenantWorkforceSetting extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'enforce_scheduled_clocking', 'clock_early_minutes', 'clock_late_minutes', 'updated_by_user_id'];

    protected $casts = ['tenant_id' => 'integer', 'enforce_scheduled_clocking' => 'boolean', 'clock_early_minutes' => 'integer', 'clock_late_minutes' => 'integer', 'updated_by_user_id' => 'integer'];
}
