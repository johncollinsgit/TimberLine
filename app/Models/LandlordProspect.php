<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandlordProspect extends Model
{
    protected $fillable = [
        'business_name',
        'contact_name',
        'trade',
        'county',
        'city',
        'website',
        'email',
        'phone',
        'status',
        'source',
        'notes',
        'last_contacted_at',
        'responded_at',
        'next_follow_up_at',
        'converted_tenant_id',
        'converted_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
            'responded_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'converted_at' => 'datetime',
            'converted_tenant_id' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }

    public function communications(): HasMany
    {
        return $this->hasMany(LandlordProspectCommunication::class);
    }

    public function convertedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'converted_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
