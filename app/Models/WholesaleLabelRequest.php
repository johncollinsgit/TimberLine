<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WholesaleLabelRequest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'wholesale_label_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'groups' => 'array', 'instructions' => 'encrypted', 'bound_at' => 'datetime'];
    }
}
