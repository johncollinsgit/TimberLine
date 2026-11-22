<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StudioEpisodeDelivery extends Model
{
    use BelongsToTenant;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['details'];
    protected $casts = ['details' => 'encrypted:array'];

    public static function receiptId(int $tenant, string $provider, string $episode, string $revision, string $digest): string
    {
        return hash('sha256', implode('|', [$tenant, $provider, $episode, $revision, $digest]));
    }
}
