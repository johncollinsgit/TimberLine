<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordProspectCommunication extends Model
{
    protected $fillable = [
        'landlord_prospect_id',
        'direction',
        'channel',
        'status',
        'subject',
        'body',
        'from_address',
        'to_address',
        'external_message_id',
        'occurred_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'landlord_prospect_id' => 'integer',
            'occurred_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(LandlordProspect::class, 'landlord_prospect_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
