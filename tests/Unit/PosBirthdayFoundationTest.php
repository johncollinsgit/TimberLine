<?php

use App\Services\Pos\PosBirthdayCandidatePolicy;
use App\Services\Pos\PosBirthdayLifecyclePolicy;
use App\Services\Pos\PosException;

uses(Tests\TestCase::class);

it('previews only explicitly enabled tenant birthday terms and never exposes a redeemable benefit', function (): void {
    $policy = ['enabled' => true, 'channel' => 'in_person', 'mode' => 'pos_only', 'amount_minor' => 500,
        'days_before' => 1, 'days_after' => 0, 'timezone' => 'America/New_York', 'leap_day' => 'feb28'];
    $clock = new DateTimeImmutable('2026-01-01T04:59:00+00:00');
    $service = new PosBirthdayCandidatePolicy;
    expect($service->inspect(1, 1, 1, 2026, $policy, 'unissued', $clock))->toBe(['state' => 'disabled', 'redeemable' => false]);
    config(['pos_live.in_person_birthday_enabled' => true]);
    $candidate = $service->inspect(1, 1, 1, 2026, $policy, 'unissued', $clock);
    expect($candidate['state'])->toBe('candidate_for_review')->and($candidate['redeemable'])->toBeFalse()
        ->and($candidate['amount_minor'])->toBe(500)->and($candidate['cycle_year'])->toBe(2026)
        ->and(strlen($candidate['policy_version']))->toBe(64);
    expect($service->inspect(2, 1, 1, 2026, $policy, 'unissued', $clock)['policy_version'])->not->toBe($candidate['policy_version']);
    foreach (['online_issued', 'uncertain', 'legacy_covered'] as $coverage) {
        expect($service->inspect(1, 1, 1, 2026, $policy, $coverage, $clock))
            ->toBe(['state' => 'coverage_review_required', 'redeemable' => false]);
    }
    expect($service->inspect(1, 1, 1, 2026, $policy, 'unissued', new DateTimeImmutable('2026-01-02T05:01:00+00:00')))
        ->toBe(['state' => 'outside_window', 'redeemable' => false]);
    expect(fn () => $service->inspect(1, 1, 1, 2026, [...$policy, 'amount_minor' => -1], 'unissued', $clock))->toThrow(PosException::class);
    expect(fn () => $service->inspect(1, 1, 1, 2026, [...$policy, 'timezone' => 'local'], 'unissued', $clock))->toThrow(PosException::class);
    expect(fn () => $service->inspect(1, 1, 1, 2026, [...$policy, 'extra_rule' => true], 'unissued', $clock))->toThrow(PosException::class);
});

it('requires an explicit tenant leap-day rule and uses the configured local calendar', function (): void {
    config(['pos_live.in_person_birthday_enabled' => true]);
    $policy = ['enabled' => true, 'channel' => 'in_person', 'mode' => 'pos_only', 'amount_minor' => 1000,
        'days_before' => 0, 'days_after' => 0, 'timezone' => 'America/New_York', 'leap_day' => 'feb28'];
    $service = new PosBirthdayCandidatePolicy;
    $feb28 = new DateTimeImmutable('2025-02-28T17:00:00+00:00');
    expect($service->inspect(1, 2, 29, 2025, $policy, 'unissued', $feb28)['state'])->toBe('candidate_for_review')
        ->and($service->inspect(1, 2, 29, 2025, [...$policy, 'leap_day' => 'mar01'], 'unissued', $feb28)['state'])->toBe('outside_window');
    expect(fn () => $service->inspect(1, 2, 30, 2025, $policy, 'unissued', $feb28))->toThrow(PosException::class);
});

it('keeps uncertain birthday payments held and routes contradictions and refunds to review', function (): void {
    $lifecycle = new PosBirthdayLifecyclePolicy;
    expect($lifecycle->next('held', 'payment_uncertain'))->toBe('held')
        ->and($lifecycle->next('held', 'verified_payment_succeeded'))->toBe('consumed')
        ->and($lifecycle->next('consumed', 'verified_payment_succeeded'))->toBe('consumed')
        ->and($lifecycle->next('held', 'verified_unstarted_discard'))->toBe('released')
        ->and($lifecycle->next('held', 'verified_terminal_unpaid'))->toBe('released')
        ->and($lifecycle->next('released', 'verified_terminal_unpaid'))->toBe('released')
        ->and($lifecycle->next('released', 'payment_uncertain'))->toBe('review')
        ->and($lifecycle->next('released', 'verified_payment_succeeded'))->toBe('review')
        ->and($lifecycle->next('consumed', 'verified_terminal_unpaid'))->toBe('review')
        ->and($lifecycle->next('consumed', 'verified_refund'))->toBe('review')
        ->and($lifecycle->next('review', 'verified_payment_succeeded'))->toBe('review');
    expect(fn () => $lifecycle->next('held', 'sdk_cancelled'))->toThrow(PosException::class);
});
