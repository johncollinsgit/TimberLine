<?php

namespace App\Services;

use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MarketDurationTemplateService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function templates(): array
    {
        return Cache::remember(
            $this->cacheKey(),
            now()->addHours(24),
            fn (): array => collect([1, 2, 3])
                ->map(fn (int $days): array => $this->buildTemplate($days))
                ->all()
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function templateForDays(int $days): array
    {
        $days = max(1, min(3, $days));

        return collect($this->templates())
            ->first(fn (array $row): bool => (int) ($row['day_count'] ?? 0) === $days)
            ?? $this->buildTemplate($days);
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildTemplate(int $days): array
    {
        $instances = EventInstance::query()
            ->with(['boxPlans' => fn ($query) => $query->whereNotNull('box_count_sent')->orderBy('id')])
            ->whereNotNull('starts_at')
            ->get(['id', 'starts_at', 'ends_at'])
            ->filter(function (EventInstance $instance) use ($days): bool {
                $startsAt = $instance->starts_at;
                if (! $startsAt) {
                    return false;
                }

                $endsAt = $instance->ends_at ?: $startsAt;
                $duration = (int) $startsAt->diffInDays($endsAt) + 1;

                return $duration === $days;
            })
            ->values();

        $topScents = $this->topScents();
        $averageBoxes = $instances->isEmpty()
            ? (float) ($days * 6)
            : (float) round(
                $instances->avg(fn (EventInstance $instance) => (float) $instance->boxPlans->sum(fn (EventBoxPlan $line) => (float) ($line->box_count_sent ?? 0))),
                1
            );

        $lineBlueprints = $this->buildLinesForTemplate($topScents, $averageBoxes);

        return [
            'day_count' => $days,
            'label' => "{$days}-Day Starter",
            'average_boxes' => $averageBoxes,
            'scent_count' => count($lineBlueprints),
            'available' => ! empty($lineBlueprints),
            'lines' => $lineBlueprints,
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function topScents(): array
    {
        return EventBoxPlan::query()
            ->selectRaw('scent_raw, SUM(COALESCE(box_count_sent, 0)) as sent_total')
            ->where('is_split_box', false)
            ->whereNotNull('box_count_sent')
            ->whereRaw("TRIM(COALESCE(scent_raw, '')) != ''")
            ->groupBy('scent_raw')
            ->orderByDesc('sent_total')
            ->limit(15)
            ->pluck('scent_raw')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $topScents
     * @return array<int,array<string,mixed>>
     */
    protected function buildLinesForTemplate(array $topScents, float $averageBoxes): array
    {
        if ($topScents === []) {
            return [];
        }

        $totalHalfBoxUnits = max(1, (int) round($averageBoxes * 2));
        $scentCount = count($topScents);
        $baseUnits = intdiv($totalHalfBoxUnits, $scentCount);
        $remainder = $totalHalfBoxUnits % $scentCount;

        $lines = [];
        foreach ($topScents as $index => $scentRaw) {
            $units = $baseUnits + ($index < $remainder ? 1 : 0);
            if ($units <= 0) {
                continue;
            }

            $lines[] = [
                'scent_raw' => $scentRaw,
                'half_box_units' => $units,
                'box_tier' => 'standard',
            ];
        }

        return $lines;
    }

    public function forgetCachedTemplates(): void
    {
        Cache::forget($this->cacheKey());
    }

    protected function cacheKey(): string
    {
        return 'markets:duration-templates:v1';
    }
}
