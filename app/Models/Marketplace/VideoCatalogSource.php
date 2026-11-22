<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class VideoCatalogSource extends Model
{
    protected $table = 'marketplace_video_catalog_sources';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'selections' => 'array'];
    }
}
