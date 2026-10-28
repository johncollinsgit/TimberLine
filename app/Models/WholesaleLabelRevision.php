<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WholesaleLabelRevision extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'wholesale_label_revisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'settings' => 'array', 'approved_at' => 'datetime'];
    }
}
