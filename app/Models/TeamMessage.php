<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMessage extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'team_channel_id', 'created_by_user_id', 'parent_message_id', 'client_uuid', 'body', 'mention_user_ids', 'reactions', 'edited_at', 'deleted_at'];

    protected $casts = ['tenant_id' => 'integer', 'team_channel_id' => 'integer', 'created_by_user_id' => 'integer', 'parent_message_id' => 'integer', 'mention_user_ids' => 'array', 'reactions' => 'array', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(TeamChannel::class, 'team_channel_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
