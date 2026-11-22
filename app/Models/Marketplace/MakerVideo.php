<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class MakerVideo extends Model
{
    protected $table = 'marketplace_videos';

    protected $guarded = [];

    protected $hidden = ['source_path', 'render_path'];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime', 'revision' => 'integer', 'duration' => 'float'];
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(Maker::class, 'maker_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(MakerVideoTag::class, 'video_id');
    }

    public static function invited(int $makerId): bool
    {
        return DB::table('marketplace_video_invitations')->where('maker_id', $makerId)->exists();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'approved')->whereNotNull('render_path')->where('publish_at', '<=', now())
            ->whereHas('maker', fn (Builder $maker) => $maker->where('status', 'approved'));
    }
}
