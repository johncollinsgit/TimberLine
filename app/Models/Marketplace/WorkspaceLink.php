<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class WorkspaceLink extends Model
{
    protected $table = 'marketplace_workspace_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
