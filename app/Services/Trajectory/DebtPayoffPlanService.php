<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;

class DebtPayoffPlanService
{
    public function current(Space $space): array
    {
        $settings = $space->settings ?? [];

        return [
            'method' => $settings['debt_payoff_method'] ?? null,
            'extra_cents' => (int) ($settings['debt_extra_cents'] ?? 0),
            'version' => (int) ($settings['debt_payoff_version'] ?? 0),
            'effective_from' => $settings['debt_payoff_effective_from'] ?? null,
        ];
    }

    /** Called with the owner-authorized space locked for the surrounding settings update. */
    public function changes(Space $space, string $method, int $extra, int $version): array
    {
        abort_unless($space->kind === 'household', 422, 'Choose a household for a debt payoff plan.');
        $current = $this->current($space);
        abort_if($current['version'] !== $version, 409, 'Your debt payoff plan changed. Refresh the plan before saving.');
        $extra = $method === 'minimum' ? 0 : $extra;
        $month = CarbonImmutable::now($space->timezone)->format('Y-m');
        if ($current['method'] === $method && $current['extra_cents'] === $extra) {
            return [];
        }

        $changes = ['debt_payoff_method' => $method, 'debt_extra_cents' => $extra,
            'debt_payoff_version' => $version + 1, 'debt_payoff_effective_from' => $month];
        if ($current['effective_from'] !== null && $current['effective_from'] < $month) {
            // Past months retain their last elected plan. Only the current setting is editable.
            $history = $space->settings['debt_payoff_history'] ?? [];
            $history[$current['effective_from']] = ['method' => $current['method'], 'extra_cents' => $current['extra_cents']];
            $changes['debt_payoff_history'] = $history;
        }

        return $changes;
    }

    public function reservation(Space $space, string $month): ?array
    {
        $plan = $this->current($space);
        if (! $plan['effective_from']) {
            return null;
        }
        if ($month < $plan['effective_from']) {
            $history = $space->settings['debt_payoff_history'] ?? [];
            krsort($history);
            $effective = array_key_first(array_filter($history, fn ($start) => $start <= $month, ARRAY_FILTER_USE_KEY));
            if ($effective === null) {
                return null;
            }
            $plan = [...$history[$effective], 'effective_from' => $effective];
        }

        return $plan['extra_cents'] > 0 ? ['label' => 'Extra debt payoff', 'amount_cents' => $plan['extra_cents'],
            'method' => $plan['method'], 'effective_from' => $plan['effective_from']] : null;
    }
}
