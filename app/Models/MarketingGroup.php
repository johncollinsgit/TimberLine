<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_internal',
        'created_by',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MarketingGroupMember::class, 'marketing_group_id');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(MarketingProfile::class, 'marketing_group_members', 'marketing_group_id', 'marketing_profile_id')
            ->withPivot(['added_by', 'created_at'])
            ->withTimestamps();
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(MarketingCampaign::class, 'marketing_campaign_groups', 'marketing_group_id', 'campaign_id')
            ->withTimestamps();
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(MarketingGroupImportRun::class, 'marketing_group_id');
    }
}
