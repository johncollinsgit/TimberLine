<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SoccerEligibilityService
{
    public function __construct(private SoccerStore $store) {}

    private function seasonOrder(string $key): ?int
    {
        return preg_match('/^(spring|fall)-(\d{4})$/', $key, $match)
            ? (int) $match[2] * 2 + ($match[1] === 'fall' ? 1 : 0) : null;
    }

    public function returning(int $tenant, object $player, string $seasonKey): bool
    {
        $current = $this->seasonOrder($seasonKey);
        if ($current === null) {
            return false;
        }
        $prior = $this->store->query('seasons', $tenant)->get()->filter(function ($season) use ($current) {
            $order = $this->seasonOrder($season->season_key);

            return $order !== null && $order < $current;
        });

        return $this->store->query('registrations', $tenant)->where('family_id', $player->family_id)
            ->where('player_id', $player->id)->whereIn('season_id', $prior->pluck('id'))
            ->whereIn('status', ['historical', 'registered'])->exists()
            || $this->store->query('releases', $tenant)->where('family_id', $player->family_id)
                ->where('player_id', $player->id)->whereIn('season_key', $prior->pluck('season_key'))
                ->whereNotNull('signed_at')->where('source_key', 'like', 'd1:%')->exists();
    }

    public function assess(int $tenant, object $player, object $season): array
    {
        $returning = $this->returning($tenant, $player, $season->season_key);
        $cutoff = $season->age_as_of ?? $season->starts_on;
        $age = $player->birthday && $cutoff
            ? (int) CarbonImmutable::parse($player->birthday)->diffInYears(CarbonImmutable::parse($cutoff)) : null;
        $reason = match (true) {
            $age === null => 'Complete the birthday and season age cutoff before registering.',
            $age < 3 => 'Saved for a future season. Players must be at least 3 on the season age cutoff; no registration fee is due for this child yet.',
            $age > ($returning ? 12 : 10) => $returning
                ? 'Returning players may register through age 12. Text Sarah Collins at 678-644-1829 with eligibility questions.'
                : 'New players must be ages 3–10. Returning players may register through age 12 when prior participation is on file. Text Sarah Collins at 678-644-1829 if a returning player’s history needs review.',
            default => null,
        };

        return ['season_key' => $season->season_key, 'age' => $age, 'returning' => $returning, 'eligible' => $reason === null, 'reason' => $reason];
    }

    public function assertEligible(int $tenant, object $player, object $season): void
    {
        $result = $this->assess($tenant, $player, $season);
        if (! $result['eligible']) {
            throw ValidationException::withMessages(['children' => $player->name.': '.$result['reason']]);
        }
    }
}
