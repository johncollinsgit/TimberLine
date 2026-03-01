<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EventInstance extends Model
{
    protected $fillable = [
        'title',
        'venue',
        'city',
        'state',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'primary_runner',
        'days_attended',
        'selling_hours',
        'total_sales',
        'boxes_sold',
        'source_file',
        'source_sheet',
        'import_batch_id',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'days_attended' => 'integer',
        'selling_hours' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'boxes_sold' => 'decimal:2',
    ];

    public function boxPlans(): HasMany
    {
        return $this->hasMany(EventBoxPlan::class);
    }

    public static function normalizeSeriesTitle(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}(?:\s*[-–]\s*\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})?\b/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim((string) $value, " \t\n\r\0\x0B,-");
    }

    public static function formatImportedTitle(?string $title, ?string $state = null): string
    {
        $clean = static::normalizeSeriesTitle($title);
        $clean = preg_replace('/\s*,\s*[A-Za-z]{2}$/u', '', $clean) ?? $clean;
        $clean = trim((string) $clean, " \t\n\r\0\x0B,");

        if ($clean === '') {
            return '';
        }

        $state = Str::upper(substr(trim((string) $state), 0, 2));

        return $state !== '' ? "{$clean}, {$state}" : $clean;
    }

    public static function seriesKey(?string $value): string
    {
        $normalized = static::normalizeSeriesTitle($value);
        $normalized = Str::lower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim((string) $normalized);
    }

    public static function dayDistance(?CarbonInterface $upcoming, ?CarbonInterface $historical): ?int
    {
        if (! $upcoming || ! $historical) {
            return null;
        }

        $anchored = $historical->copy()->year((int) $upcoming->year);

        return abs($upcoming->diffInDays($anchored, false));
    }
}
