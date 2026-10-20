<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $table = 'marketplace_memberships';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function maker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Maker::class, 'maker_id');
    }

    public function identity()
    {
        return $this->belongsTo(Identity::class);
    }
}
