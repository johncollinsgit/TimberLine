<?php

namespace App\Services;

use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use Illuminate\Support\Facades\Cache;

class MarketDurationTemplateService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function templates(): array
    {
        $dayCounts = [1, 2, 3];

        return Cache::remember(
            $this->cacheKey(),
            now()->addHours(24),
            function () use ($dayCounts): array {
                $topScents = $this->topScents();
                $averageBoxesByDuration = $this->averageBoxesByDuration($dayCounts);

                return collect($dayCounts)
                    ->map(fn (int $days): array => $this->buildTemplate(
                        $days,
                        $topScents,
                        (float) ($averageBoxesByDuration[$days] ?? ($days * 6))
                    ))
                    ->all();
            }
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
            ?? $this->buildTemplate($days, $this->topScents(), (float) ($days * 6));
    }

    /**
     * @param  array<int,int>  $dayCounts
     * @return array<int,float>
     */
    protected function averageBoxesByDuration(array $dayCounts): array
    {
        $averages = array_fill_keys($dayCounts, 0.0);
        $instances = EventInstance::query()
            ->with(['boxPlans' => fn ($query) => $query->whereNotNull('box_count_sent')])
            ->whereNotNull('starts_at')
            ->get(['id', 'starts_at', 'ends_at']);

        foreach ($dayCounts as $dayCount) {
            $matching = $instances->filter(
                fn (EventInstance $instance): bool => $this->durationDaysForInstance($instance) === $dayCount
            );

            $averages[$dayCount] = $matching->isEmpty()
                ? (float) ($dayCount * 6)
                : (float) round(
                    $matching->avg(
                        fn (EventInstance $instance) => (float) $instance->boxPlans->sum(
                            fn (EventBoxPlan $line) => (float) ($line->box_count_sent ?? 0)
                        )
                    ),
                    1
                );
        }

        return $averages;
    }

    protected function durationDaysForInstance(EventInstance $instance): int
    {
        $startsAt = $instance->starts_at;
        if (! $startsAt) {
            return 0;
        }

        $endsAt = $instance->ends_at ?: $startsAt;

        return max(1, (int) $startsAt->diffInDays($endsAt) + 1);
    }

    /**
     * @param  array<int,string>  $topScents
     * @return array<string,mixed>
     */
    protected function buildTemplate(int $days, array $topScents, float $averageBoxes): array
    {
        $days = max(1, min(3, $days));
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
            ->whereRaw("LOWER(TRIM(COALESCE(scent_raw, ''))) != 'top shelf'")
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
