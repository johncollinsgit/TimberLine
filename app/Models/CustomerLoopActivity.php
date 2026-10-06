<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerLoopActivity extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'marketing_profile_id', 'actor_user_id', 'source_type', 'source_id', 'event_key', 'title', 'summary', 'safe_context', 'occurred_at'];

    protected $casts = ['safe_context' => 'array', 'occurred_at' => 'datetime'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(CustomerLoopAction::class);
    }
}
