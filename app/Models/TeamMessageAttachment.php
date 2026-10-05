<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMessageAttachment extends Model
{
    use HasTenantScope;

    protected $guarded = ['id'];

    protected $casts = ['file_size' => 'integer', 'received_bytes' => 'integer', 'expires_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'team_message_id');
    }
}
