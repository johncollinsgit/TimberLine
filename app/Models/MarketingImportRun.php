<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Support\Collection;

class MarketingImportRun extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'type',
        'status',
        'source_label',
        'file_name',
        'started_at',
        'finished_at',
        'summary',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(MarketingImportRow::class);
    }

    public static function latestForTenant(int $tenantId, ?array $types = null, int $limit = 6): Collection
    {
        $query = self::query()->where('tenant_id', $tenantId);

        if (! empty($types)) {
            $query->whereIn('type', $types);
        }

        return $query->orderByDesc('id')->limit($limit)->get();
    }

    public static function tenantScopedRun(int $runId, string $type, int $tenantId): ?self
    {
        return self::query()
            ->where('id', $runId)
            ->where('type', $type)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
