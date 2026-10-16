<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingAutomationEnrollment extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_automation_enrollments';

    protected $guarded = [];

    protected $attributes = ['status' => 'active'];

    protected $casts = ['started_at' => 'datetime'];
}
