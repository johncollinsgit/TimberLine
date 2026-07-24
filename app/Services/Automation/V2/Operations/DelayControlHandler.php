<?php

namespace App\Services\Automation\V2\Operations;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Contracts\ControlOperation;
use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use App\Services\Automation\V2\TypedValueMapper;
use Carbon\CarbonImmutable;

class DelayControlHandler implements ControlOperation
{
    public const MINIMUM_SECONDS = 60;

    public const MAXIMUM_SECONDS = 2_592_000;

    public function __construct(protected TypedValueMapper $mapper) {}

    public function evaluate(array $step, WorkflowExecutionContext $context): ControlResult
    {
        $componentKey = strtolower(trim((string) ($step['component_key'] ?? '')));
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $now = CarbonImmutable::now();

        return match ($componentKey) {
            'core.delay_for' => $this->delayFor($config, $context, $now),
            'core.delay_until' => $this->delayUntil($config, $context, $now),
            default => throw new AutomationWorkflowException('This delay type is not executable.'),
        };
    }

    /**
     * @param  array<string,mixed>  $config
     */
    protected function delayFor(
        array $config,
        WorkflowExecutionContext $context,
        CarbonImmutable $now
    ): ControlResult {
        $value = $this->mapper->resolve($config['duration'] ?? null, $context);
        if (! is_numeric($value)) {
            throw new AutomationWorkflowException('Delay For needs a number.');
        }

        $unit = strtolower(trim((string) ($config['unit'] ?? 'minutes')));
        $seconds = (float) $value * match ($unit) {
            'minute', 'minutes' => 60,
            'hour', 'hours' => 3_600,
            'day', 'days' => 86_400,
            'week', 'weeks' => 604_800,
            default => throw new AutomationWorkflowException('Delay For uses an unsupported time unit.'),
        };
        $seconds = (int) round($seconds);
        $this->assertDuration($seconds);

        $availableAt = $now->addSeconds($seconds);

        return ControlResult::delay($availableAt, [
            'delay_type' => 'for',
            'available_at' => $availableAt->toIso8601String(),
            'duration_seconds' => $seconds,
        ]);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    protected function delayUntil(
        array $config,
        WorkflowExecutionContext $context,
        CarbonImmutable $now
    ): ControlResult {
        $value = $this->mapper->resolve($config['datetime'] ?? null, $context);
        try {
            $availableAt = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw new AutomationWorkflowException('Delay Until needs a valid date and time.');
        }

        if ($availableAt->isFuture()) {
            $seconds = (int) round($now->diffInSeconds($availableAt));
            if ($seconds < self::MINIMUM_SECONDS) {
                throw new AutomationWorkflowException('The shortest supported delay is one minute.');
            }
            if ($seconds > self::MAXIMUM_SECONDS) {
                throw new AutomationWorkflowException('A workflow can be delayed for no more than 30 days.');
            }

            return ControlResult::delay($availableAt, [
                'delay_type' => 'until',
                'available_at' => $availableAt->toIso8601String(),
                'duration_seconds' => $seconds,
            ]);
        }

        $behavior = strtolower(trim((string) (
            $config['past_date_behavior']
            ?? $config['past_behavior']
            ?? 'continue_if_within_1_day'
        )));
        $ageSeconds = $availableAt->diffInSeconds($now);
        $threshold = match ($behavior) {
            'continue', 'always_continue' => PHP_INT_MAX,
            'continue_if_within_15_minutes' => 900,
            'continue_if_within_1_hour' => 3_600,
            'continue_if_within_1_day' => 86_400,
            'fail' => -1,
            default => throw new AutomationWorkflowException('Delay Until uses an unsupported past-date behavior.'),
        };

        if ($ageSeconds > $threshold) {
            throw new AutomationWorkflowException('The Delay Until date has already passed.');
        }

        return ControlResult::continue(
            [
                'resume_at' => $now->toIso8601String(),
                'released_immediately' => true,
            ],
            [
                'delay_type' => 'until',
                'available_at' => $availableAt->toIso8601String(),
                'released_immediately' => true,
            ]
        );
    }

    protected function assertDuration(int $seconds): void
    {
        if ($seconds < self::MINIMUM_SECONDS) {
            throw new AutomationWorkflowException('The shortest supported delay is one minute.');
        }
        if ($seconds > self::MAXIMUM_SECONDS) {
            throw new AutomationWorkflowException('A workflow can be delayed for no more than 30 days.');
        }
    }
}
