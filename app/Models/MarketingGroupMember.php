<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingGroupMember extends Model
{
    protected $fillable = [
        'marketing_group_id',
        'marketing_profile_id',
        'added_by',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(MarketingGroup::class, 'marketing_group_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
