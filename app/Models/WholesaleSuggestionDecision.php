<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WholesaleSuggestionDecision extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'original_suggestion' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(WholesaleSuggestion::class, 'wholesale_suggestion_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new RuntimeException('Wholesale suggestion decisions are append-only.'));
        static::deleting(fn (): never => throw new RuntimeException('Wholesale suggestion decisions are append-only.'));
    }
}
