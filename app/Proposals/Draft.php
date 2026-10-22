<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Draft extends Model
{
    use HasUuids;

    protected $table = 'service_proposal_drafts';

    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array'];
}
