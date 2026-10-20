<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $table = 'marketplace_ledger_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['eligible_at' => 'datetime', 'amount_cents' => 'integer'];
    }
}
