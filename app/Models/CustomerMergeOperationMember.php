<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMergeOperationMember extends Model
{
    protected $guarded = [];

    protected $casts = [
        'marketing_profile_id' => 'integer',
        'snapshot' => 'array',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CustomerMergeOperation::class, 'customer_merge_operation_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
