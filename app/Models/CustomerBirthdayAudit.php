<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBirthdayAudit extends Model
{
    protected $fillable = [
        'customer_birthday_profile_id',
        'marketing_profile_id',
        'action',
        'source',
        'is_uncertain',
        'payload',
    ];

    protected $casts = [
        'is_uncertain' => 'boolean',
        'payload' => 'array',
    ];

    public function birthdayProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerBirthdayProfile::class, 'customer_birthday_profile_id');
    }

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
