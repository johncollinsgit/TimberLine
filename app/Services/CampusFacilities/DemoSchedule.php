<?php

namespace App\Services\CampusFacilities;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class DemoSchedule
{
    public function conflicts(array $state, ?array $candidate = null): array
    {
        $rows = $state['operations']['bookings'];
        $buffer = $state['operations']['settings']['bufferMinutes'];
        $conflicts = [];
        foreach ($candidate ? [$candidate] : $rows as $row) {
            if ($row['status'] === 'Cancelled') {
                continue;
            }
            $start = CarbonImmutable::parse($row['startsAt']);
            $end = CarbonImmutable::parse($row['endsAt']);
            foreach ($rows as $other) {
                if ($other['id'] === $row['id'] || $other['status'] !== 'Confirmed' || mb_strtolower($row['building']) !== mb_strtolower($other['building'])) {
                    continue;
                }
                if ($start < CarbonImmutable::parse($other['endsAt'])->addMinutes($buffer) && $end > CarbonImmutable::parse($other['startsAt'])->subMinutes($buffer)) {
                    $conflicts[] = ['bookingId' => $row['id'], 'otherId' => $other['id'], 'title' => $row['title'], 'otherTitle' => $other['title'], 'building' => $row['building']];
                }
            }
        }

        return $conflicts;
    }

    public function handle(array &$state, array $actor, string $action, string $id, int $revision, array $data): array
    {
        $records = app(DemoRecords::class);
        $directory = app(DemoDirectory::class);
        $settings = $state['operations']['settings'];
        $old = $revision ? $records->existing($state, 'bookings', $id, $revision) : null;
        if ($action === 'booking.save') {
            $data = $records->data($data, [
                'title' => 'required|string|min:5|max:150', 'building' => 'required|string|min:2|max:120',
                'startsAt' => 'required|date|max:40', 'endsAt' => 'required|date|max:40',
                'kind' => ['required', Rule::in(['Maintenance', 'Class', 'Athletics', 'Event'])],
                'owner' => 'required|string|max:50', 'ticketId' => 'nullable|string|max:50',
                'resolution' => 'nullable|string|max:1000',
            ]);
            $records->person($state, $data['owner']);
            if ($data['ticketId'] ?? null) {
                $ticket = $records->ticket($state, $data['ticketId']);
                abort_unless(app(Tickets::class)->canSee($ticket, $actor), 403, 'Use the responsible work-order view.');
            }
            if (in_array($data['kind'], ['Class', 'Athletics'], true) || ($old && in_array($old['kind'], ['Class', 'Athletics'], true))) {
                $records->requireReviewer($actor, $settings['conflictRole']);
            }
            $start = $this->time($data['startsAt'], $settings['timezone']);
            $end = $this->time($data['endsAt'], $settings['timezone']);
            abort_unless($end > $start && $end <= $start->addDays(7), 422, 'Choose a time window after its start and no longer than seven days.');
            if ($old && $old['status'] === 'Confirmed' && ($start->toIso8601String() !== $old['startsAt'] || $end->toIso8601String() !== $old['endsAt'] || $data['building'] !== $old['building'])) {
                $records->requireReviewer($actor, $settings['conflictRole']);
                abort_if(mb_strlen(trim($data['resolution'] ?? '')) < 5, 422, 'Record the agreed reason before moving a confirmed activity.');
            }
            $row = [...$data, 'id' => $id, 'startsAt' => $start->toIso8601String(), 'endsAt' => $end->toIso8601String(), 'ticketId' => $data['ticketId'] ?? null, 'resolution' => $data['resolution'] ?? '', 'status' => $old['status'] ?? 'Proposed'];
            if ($row['status'] === 'Confirmed') {
                abort_if($this->conflicts($state, $row), 422, 'This window conflicts with a confirmed activity or its buffer. Review a different time.');
            }
            $records->save($state, 'bookings', $id, $revision, $row);

            return ['message' => 'Schedule window saved. Confirm it after reviewing conflicts.', 'resourceId' => $id];
        }
        abort_unless($old, 404, 'Choose an existing schedule window.');
        $data = $records->data($data, ['resolution' => 'required|string|min:5|max:1000']);
        $records->requireReviewer($actor, $settings['conflictRole']);
        if ($action === 'booking.confirm') {
            abort_unless($old['status'] === 'Proposed', 422, 'Review a proposed window before confirming it.');
            abort_if($this->conflicts($state, $old), 422, 'Resolve the overlap first. The other activity will not be moved automatically.');
            $old['status'] = 'Confirmed';
        } elseif ($action === 'booking.cancel') {
            abort_unless($old['status'] !== 'Cancelled', 422, 'This activity is already cancelled.');
            $old['status'] = 'Cancelled';
        } else {
            abort(422, 'Unsupported calendar action.');
        }
        $records->save($state, 'bookings', $id, $revision, [...$old, 'resolution' => $data['resolution']]);

        return ['message' => 'Schedule decision recorded with its reason.', 'resourceId' => $id];
    }

    private function time(string $value, string $timezone): CarbonImmutable
    {
        $time = CarbonImmutable::parse($value, $timezone);
        if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D', $value)) {
            abort_unless($time->format('Y-m-d\TH:i') === $value, 422, 'Choose a valid local time in the selected time zone.');
        }

        return $time;
    }
}
