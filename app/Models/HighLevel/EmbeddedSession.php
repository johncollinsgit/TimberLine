<?php

namespace App\Models\HighLevel;

use Illuminate\Database\Eloquent\Model;

class EmbeddedSession extends Model
{
    protected $table = 'highlevel_sessions';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function installation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function binding(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UserBinding::class, 'binding_id');
    }
}
