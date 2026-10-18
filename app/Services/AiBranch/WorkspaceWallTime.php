<?php

namespace App\Services\AiBranch;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class WorkspaceWallTime
{
    public function resolve(string $local, string $timezone, ?string $offset = null): array
    {
        abort_unless(preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local), 422, 'Choose a date and time between 2000 and 2099.');
        try {
            $naive = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, 'UTC');
        } catch (\Throwable) {
            abort(422, 'Choose a valid due time.');
        }
        abort_unless($naive && $naive->format('Y-m-d\TH:i') === $local, 422, 'Choose a valid due time.');
        abort_unless(in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true), 422);
        $zone = new DateTimeZone($timezone);
        $offsets = array_unique(array_column($zone->getTransitions($naive->timestamp - 172800, $naive->timestamp + 172800), 'offset'));
        $choices = [];
        foreach ($offsets as $seconds) {
            $instant = $naive->subSeconds($seconds);
            if ($instant->setTimezone($zone)->format('Y-m-d\TH:i') === $local) {
                $choices[] = $instant;
            }
        }
        if ($choices === []) {
            throw ValidationException::withMessages(['due_local' => 'That local time does not exist because the clock changes. Choose another time.']);
        }
        if ($offset === null && count($choices) > 1) {
            $labels = array_map(fn ($instant) => $instant->setTimezone($zone)->format('P'), $choices);
            throw ValidationException::withMessages(['due_offset' => 'That time occurs twice. Choose an explicit UTC offset: '.implode(' or ', $labels).'.']);
        }
        if ($offset !== null) {
            $choices = array_values(array_filter($choices, fn ($instant) => $instant->setTimezone($zone)->format('P') === $offset));
            if (count($choices) !== 1) {
                throw ValidationException::withMessages(['due_offset' => 'That UTC offset does not match the selected workspace time.']);
            }
        }
        $due = $choices[0];

        return ['utc' => $due->toIso8601String(), 'local' => $due->setTimezone($zone)->format('Y-m-d H:i P'), 'timezone' => $timezone];
    }
}
