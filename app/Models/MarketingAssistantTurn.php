<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingAssistantTurn extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_assistant_turns';

    protected $guarded = [];

    protected $attributes = ['status' => 'reserved'];

    protected $hidden = ['input', 'result'];

    protected $casts = ['input' => 'encrypted:array', 'result' => 'encrypted:array'];
}
