<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasUuids;

    protected $table = 'service_proposal_assets';

    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array'];
}
