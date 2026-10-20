<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Identity extends Model
{
    protected $table = 'marketplace_identities';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function memberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Membership::class, 'identity_id');
    }
}
