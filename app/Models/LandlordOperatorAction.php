<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class LandlordOperatorAction extends Model
{
    use HasTenantScope;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'action_type',
        'status',
        'target_type',
        'target_id',
        'context',
        'confirmation',
        'before_state',
        'after_state',
        'result',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'actor_user_id' => 'integer',
            'context' => 'array',
            'confirmation' => 'array',
            'before_state' => 'array',
            'after_state' => 'array',
            'result' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Landlord operator actions are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Landlord operator actions are append-only and cannot be deleted.');
        });
    }
}
