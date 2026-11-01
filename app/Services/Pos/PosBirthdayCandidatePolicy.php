<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** An internal preview only; this neither issues an annual entitlement nor authorizes payment. */
final class PosBirthdayCandidatePolicy
{
    public function inspect(int $tenant, int $month, int $day, int $cycleYear, array $policy, string $coverage, DateTimeInterface $at): array
    {
        if (config('pos_live.in_person_birthday_enabled', false) !== true) {
            return ['state' => 'disabled', 'redeemable' => false];
        }
        if ($tenant < 1 || $cycleYear < 1900 || $cycleYear > 9999 || ! checkdate($month, $day, 2000)
            || array_diff(array_keys($policy), ['enabled', 'channel', 'mode', 'amount_minor', 'days_before', 'days_after', 'leap_day', 'timezone'])
            || ! is_bool($policy['enabled'] ?? null) || ($policy['channel'] ?? null) !== 'in_person'
            || ($policy['mode'] ?? null) !== 'pos_only' || ! is_int($policy['amount_minor'] ?? null)
            || $policy['amount_minor'] < 1 || $policy['amount_minor'] > Contract::MAX_MINOR
            || ! is_int($policy['days_before'] ?? null) || ! is_int($policy['days_after'] ?? null)
            || $policy['days_before'] < 0 || $policy['days_before'] > 366 || $policy['days_after'] < 0 || $policy['days_after'] > 366
            || ! in_array($policy['leap_day'] ?? null, ['feb28', 'mar01'], true)
            || ! is_string($policy['timezone'] ?? null) || ! in_array($policy['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new PosException('birthday_policy_invalid', 422);
        }
        if (! $policy['enabled']) {
            return ['state' => 'disabled', 'redeemable' => false];
        }
        // Any existing or uncertain coverage includes online codes and must not
        // be duplicated or considered offline redeemable.
        if ($coverage !== 'unissued') {
            return ['state' => 'coverage_review_required', 'redeemable' => false];
        }
        if ($month === 2 && $day === 29 && ! checkdate(2, 29, $cycleYear)) {
            [$month, $day] = $policy['leap_day'] === 'feb28' ? [2, 28] : [3, 1];
        }
        $zone = new DateTimeZone($policy['timezone']);
        $birthday = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $cycleYear, $month, $day), $zone);
        $start = $birthday->modify('-'.$policy['days_before'].' days');
        $end = $birthday->modify('+'.$policy['days_after'].' days');
        $today = DateTimeImmutable::createFromInterface($at)->setTimezone($zone)->format('Y-m-d');
        if ($today < $start->format('Y-m-d') || $today > $end->format('Y-m-d')) {
            return ['state' => 'outside_window', 'redeemable' => false];
        }
        $terms = [$tenant, 'pos_only', $policy['amount_minor'], $policy['timezone'], $policy['days_before'],
            $policy['days_after'], $policy['leap_day']];

        return ['state' => 'candidate_for_review', 'redeemable' => false, 'cycle_year' => $cycleYear,
            'amount_minor' => $policy['amount_minor'], 'policy_version' => Contract::hash($terms)];
    }
}
