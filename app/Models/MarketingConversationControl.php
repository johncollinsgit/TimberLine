<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingConversationControl extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_conversation_controls';

    protected $guarded = [];

    protected $casts = [];
}
