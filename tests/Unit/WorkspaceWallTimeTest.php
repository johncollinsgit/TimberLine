<?php

use App\Services\AiBranch\WorkspaceWallTime;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

test('workspace due times reject daylight-saving gaps and require a choice for repeated times', function () {
    $clock = new WorkspaceWallTime;
    expect(fn () => $clock->resolve('2026-03-08T02:30', 'America/New_York'))->toThrow(ValidationException::class);
    expect(fn () => $clock->resolve('2026-11-01T01:30', 'America/New_York'))->toThrow(ValidationException::class);
    expect($clock->resolve('2026-11-01T01:30', 'America/New_York', '-04:00')['utc'])->toBe('2026-11-01T05:30:00+00:00')
        ->and($clock->resolve('2026-11-01T01:30', 'America/New_York', '-05:00')['utc'])->toBe('2026-11-01T06:30:00+00:00');
    expect(fn () => $clock->resolve('2026-11-01T01:30', 'America/New_York', '-06:00'))->toThrow(ValidationException::class);
});

test('workspace due times resolve explicit business time rather than the device timezone', function () {
    $clock = new WorkspaceWallTime;
    expect($clock->resolve('2026-10-20T09:00', 'America/Los_Angeles')['utc'])->toBe('2026-10-20T16:00:00+00:00')
        ->and($clock->resolve('2026-10-20T09:00', 'UTC')['utc'])->toBe('2026-10-20T09:00:00+00:00');
});

test('commitment candidate filtering distinguishes explicit promises from suggestions and conditions', function (string $quote, bool $expected) {
    expect((new \App\Services\AiBranch\AiCommitments)->isPromiseCandidate($quote))->toBe($expected);
})->with([
    ['I will call the customer.', true], ["We'll order the part.", true], ['We agreed to check the panel.', true],
    ['Maybe we could call the customer.', false], ['I will call if there is time.', false], ['I will not call.', false],
    ['The customer suggests that we will call.', false], ['We should call tomorrow.', false],
]);
