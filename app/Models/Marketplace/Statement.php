<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Statement extends Model
{
    protected $table = 'marketplace_statements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'paid_at' => 'datetime', 'amount_cents' => 'integer'];
    }

    public function entries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'statement_id');
    }

    public function maker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Maker::class, 'maker_id');
    }
}
