<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudioOffer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['closes_at' => 'datetime', 'reserved' => 'boolean'];
    }

    public function briefs(): HasMany
    {
        return $this->hasMany(StudioBrief::class);
    }
}
