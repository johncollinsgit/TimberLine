<?php

namespace App\Services\Voice;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;

class CallRoutingPolicy
{
    public function validate(array $input): array
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'timezone' => ['required', 'string', 'in:'.implode(',', DateTimeZone::listIdentifiers())],
            'hours' => 'present|array', 'holidays' => 'sometimes|array|max:100', 'targets' => 'present|array|max:10', 'targets.*' => 'array:kind,user_id,number',
            'targets.*.kind' => 'required|in:staff,forward', 'targets.*.user_id' => 'nullable|integer|min:1', 'targets.*.number' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'fallback' => 'required|in:voicemail,receptionist'])->validate();
        foreach ($data['hours'] as $day => $periods) {
            abort_unless(ctype_digit((string) $day) && (int) $day >= 1 && (int) $day <= 7, 422);
            $this->periods($periods);
        }
        foreach ($data['holidays'] ?? [] as $date => $periods) {
            abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422);
            try {
                $valid = CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') === $date;
            } catch (\Throwable) {
                $valid = false;
            }
            abort_unless($valid, 422);
            $this->periods($periods);
        }
        foreach ($data['targets'] as $target) {
            abort_unless($target['kind'] === 'staff' ? ! empty($target['user_id']) : ! empty($target['number']), 422);
        }

        return $data;
    }

    private function periods(mixed $periods): void
    {
        abort_unless(is_array($periods) && count($periods) <= 8, 422);
        $end = '';
        foreach ($periods as $period) {
            abort_unless(is_array($period) && count($period) === 2 && isset($period[0], $period[1])
                && is_string($period[0]) && is_string($period[1]) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $period[0]) && preg_match('/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/', $period[1])
                && $period[0] < $period[1] && $period[0] >= $end, 422, 'Use ordered, non-overlapping same-day periods. Split overnight hours across two days.');
            $end = $period[1];
        }
    }

    public function route(array $policy, CarbonImmutable $at, array $availableAuthorizedStaffIds, array $ownedNumbers, bool $approvedReceptionistReady): array
    {
        $policy = $this->validate($policy);
        $local = $at->setTimezone($policy['timezone']);
        $periods = $policy['holidays'][$local->toDateString()] ?? $policy['hours'][$local->isoWeekday()] ?? [];
        $open = collect($periods)->contains(fn ($period) => $local->format('H:i') >= $period[0] && $local->format('H:i') < $period[1]);
        if (! $open) {
            return ['policy_version' => $policy['version'], 'route' => 'voicemail', 'reason' => 'outside_business_hours', 'targets' => []];
        }
        $seen = [];
        $targets = [];
        foreach ($policy['targets'] as $target) {
            if ($target['kind'] === 'staff') {
                if (! in_array((int) $target['user_id'], $availableAuthorizedStaffIds, true)) {
                    continue;
                }
                $key = 'staff:'.$target['user_id'];
            } else {
                abort_if(in_array($target['number'], $ownedNumbers, true), 422, 'A forwarding target cannot be an owned number.');
                $key = 'forward:'.$target['number'];
            }
            if (! isset($seen[$key])) {
                $targets[] = $target;
                $seen[$key] = true;
            }
        }

        return ['policy_version' => $policy['version'], 'route' => $targets ? 'ring_group' : ($policy['fallback'] === 'receptionist' && $approvedReceptionistReady ? 'receptionist' : 'voicemail'),
            'reason' => $targets ? 'available_targets' : 'no_available_staff', 'targets' => $targets];
    }
}
