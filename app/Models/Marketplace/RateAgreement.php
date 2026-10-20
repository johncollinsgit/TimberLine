<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class RateAgreement extends Model
{
    protected $table = 'marketplace_rate_agreements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'integer', 'version' => 'integer'];
    }
}
