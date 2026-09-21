<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockistLocatorSetting extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'shopify_store_id' => 'integer',
            'configuration' => 'array',
            'last_imported_at' => 'datetime',
        ];
    }
}
