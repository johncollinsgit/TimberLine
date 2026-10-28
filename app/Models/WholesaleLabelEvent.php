<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WholesaleLabelEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'wholesale_label_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'details' => 'array'];
    }
}
