<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMessageGroupMember extends Model
{
    protected $fillable = [
        'marketing_message_group_id',
        'marketing_profile_id',
        'source_type',
        'full_name',
        'email',
        'phone',
        'normalized_phone',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageGroup::class, 'marketing_message_group_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
