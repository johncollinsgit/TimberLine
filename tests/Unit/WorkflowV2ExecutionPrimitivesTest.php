<?php

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\ConditionEvaluator;
use App\Services\Automation\V2\Data\ControlResult;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use App\Services\Automation\V2\Operations\DelayControlHandler;
use App\Services\Automation\V2\Operations\PathsControlHandler;
use App\Services\Automation\V2\TypedValueMapper;
use App\Services\Automation\V2\V2WorkflowInterpreter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function workflowV2ExecutionContext(array $trigger, array $steps = []): WorkflowExecutionContext
{
    return new WorkflowExecutionContext(
        tenantId: 12,
        workflowId: 34,
        workflowVersionId: 56,
        runId: 78,
        runItemId: 90,
        triggerOutput: $trigger,
        stepOutputs: $steps,
    );
}

test('typed workflow mappings resolve only trigger and prior step output', function (): void {
    $mapper = new TypedValueMapper;
    $stepId = (string) Str::ulid();
    $context = workflowV2ExecutionContext(
        ['customer' => ['name' => 'Ada'], 'wait' => '15'],
        [$stepId => ['event_id' => 'calendar-123']],
    );

    expect($mapper->resolve(
        ['type' => 'mapping', 'path' => 'trigger.output.customer.name'],
        $context,
    ))->toBe('Ada')
        ->and($mapper->resolve(
            ['type' => 'mapping', 'path' => 'trigger.output.wait', 'cast' => 'integer'],
            $context,
        ))->toBe(15)
        ->and($mapper->resolve(
            "Created {{ steps.{$stepId}.output.event_id }} for {{ trigger.output.customer.name }}",
            $context,
        ))->toBe('Created calendar-123 for Ada');

    expect(fn () => $mapper->resolve(
        ['type' => 'mapping', 'path' => 'config.app.key'],
        $context,
    ))->toThrow(AutomationWorkflowException::class);
});

test('filters implement positive and negative line item behavior', function (): void {
    $evaluator = new ConditionEvaluator(new TypedValueMapper);
    $context = workflowV2ExecutionContext([
        'names' => ['Oak table', 'Maple shelf'],
        'statuses' => ['ready', 'ready'],
        'completed' => false,
    ]);

    $positive = [
        'logic' => 'and',
        'conditions' => [[
            'field' => ['type' => 'mapping', 'path' => 'trigger.output.names'],
            'operator' => 'contains',
            'value' => ['type' => 'literal', 'value' => 'maple'],
        ]],
    ];
    $negativePasses = [
        'conditions' => [[
            'field' => ['type' => 'mapping', 'path' => 'trigger.output.statuses'],
            'operator' => 'not_equals',
            'value' => ['type' => 'literal', 'value' => 'cancelled'],
        ]],
    ];
    $negativeFails = [
        'conditions' => [[
            'field' => ['type' => 'mapping', 'path' => 'trigger.output.names'],
            'operator' => 'not_contains',
            'value' => ['type' => 'literal', 'value' => 'oak'],
        ]],
    ];

    expect($evaluator->evaluate($positive, $context))->toBeTrue()
        ->and($evaluator->evaluate($negativePasses, $context))->toBeTrue()
        ->and($evaluator->evaluate($negativeFails, $context))->toBeFalse()
        ->and($evaluator->evaluate([
            'conditions' => [[
                'field' => ['type' => 'mapping', 'path' => 'trigger.output.completed'],
                'operator' => 'equals',
                'value' => ['type' => 'literal', 'value' => false],
            ]],
        ], $context))->toBeTrue();
});

test('filters support empty and collection containment operators', function (): void {
    $evaluator = new ConditionEvaluator(new TypedValueMapper);
    $context = workflowV2ExecutionContext([
        'blank' => '',
        'tags' => ['launch', 'partner', 'local'],
        'tagline' => 'local launch partner',
    ]);

    expect($evaluator->evaluate([
        'conditions' => [[
            'field' => ['type' => 'mapping', 'path' => 'trigger.output.blank'],
            'operator' => 'is_empty',
        ]],
    ], $context))->toBeTrue()
        ->and($evaluator->evaluate([
            'conditions' => [[
                'field' => ['type' => 'mapping', 'path' => 'trigger.output.tags'],
                'operator' => 'contains_any',
                'value' => ['type' => 'literal', 'value' => ['partner', 'customer']],
            ]],
        ], $context))->toBeTrue()
        ->and($evaluator->evaluate([
            'conditions' => [[
                'field' => ['type' => 'mapping', 'path' => 'trigger.output.tagline'],
                'operator' => 'contains_all',
                'value' => ['type' => 'literal', 'value' => ['partner', 'local']],
            ]],
        ], $context))->toBeTrue();
});

test('delay controls enforce the one minute to thirty day window and past date policy', function (): void {
    CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
    $handler = new DelayControlHandler(new TypedValueMapper);
    $context = workflowV2ExecutionContext([]);

    $delay = $handler->evaluate([
        'component_key' => 'core.delay_for',
        'config' => [
            'duration' => ['type' => 'literal', 'value' => 90],
            'unit' => 'minutes',
        ],
    ], $context);
    $past = $handler->evaluate([
        'component_key' => 'core.delay_until',
        'config' => [
            'datetime' => ['type' => 'literal', 'value' => '2026-07-24 11:30:00 UTC'],
            'past_date_behavior' => 'continue_if_within_1_hour',
        ],
    ], $context);

    expect($delay->outcome)->toBe(ControlResult::DELAY)
        ->and($delay->availableAt?->toIso8601String())->toBe('2026-07-24T13:30:00+00:00')
        ->and($past->outcome)->toBe(ControlResult::CONTINUE);

    expect(fn () => $handler->evaluate([
        'component_key' => 'core.delay_for',
        'config' => [
            'duration' => ['type' => 'literal', 'value' => 30],
            'unit' => 'seconds',
        ],
    ], $context))->toThrow(AutomationWorkflowException::class);

    CarbonImmutable::setTestNow();
});

test('paths return every matching branch in editor order and fallback only when none match', function (): void {
    $handler = new PathsControlHandler(new ConditionEvaluator(new TypedValueMapper));
    $context = workflowV2ExecutionContext(['amount' => 250]);
    $first = (string) Str::ulid();
    $second = (string) Str::ulid();
    $fallback = (string) Str::ulid();
    $branches = [
        [
            'id' => $first,
            'rule_type' => 'custom',
            'condition' => [
                'conditions' => [[
                    'field' => ['type' => 'mapping', 'path' => 'trigger.output.amount'],
                    'operator' => 'greater_than',
                    'value' => ['type' => 'literal', 'value' => 100],
                ]],
            ],
        ],
        ['id' => $second, 'rule_type' => 'always'],
        ['id' => $fallback, 'rule_type' => 'fallback'],
    ];

    $matched = $handler->evaluate([
        'component_key' => 'core.paths',
        'config' => ['branches' => $branches],
    ], $context);

    expect($matched->branchIds)->toBe([$first, $second]);

    $fallbackOnly = $handler->evaluate([
        'component_key' => 'core.paths',
        'config' => ['branches' => [
            [
                'id' => $first,
                'rule_type' => 'custom',
                'condition' => [
                    'conditions' => [[
                        'field' => ['type' => 'mapping', 'path' => 'trigger.output.amount'],
                        'operator' => 'less_than',
                        'value' => ['type' => 'literal', 'value' => 10],
                    ]],
                ],
            ],
            ['id' => $fallback, 'rule_type' => 'fallback'],
        ]],
    ], $context);

    expect($fallbackOnly->branchIds)->toBe([$fallback]);
});

test('the interpreter executes matched path branches sequentially from left to right', function (): void {
    $interpreter = new V2WorkflowInterpreter;
    $pathsId = (string) Str::ulid();
    $branchA = (string) Str::ulid();
    $branchB = (string) Str::ulid();
    $actionA = (string) Str::ulid();
    $actionB = (string) Str::ulid();
    $paths = [
        'id' => $pathsId,
        'kind' => 'paths',
        'component_key' => 'core.paths',
        'config' => [
            'branches' => [
                [
                    'id' => $branchA,
                    'rule_type' => 'always',
                    'steps' => [[
                        'id' => $actionA,
                        'kind' => 'action',
                        'component_key' => 'everbranch.job.note.add',
                    ]],
                ],
                [
                    'id' => $branchB,
                    'rule_type' => 'always',
                    'steps' => [[
                        'id' => $actionB,
                        'kind' => 'action',
                        'component_key' => 'everbranch.job.note.add',
                    ]],
                ],
            ],
        ],
    ];
    $definition = ['schema_version' => 2, 'steps' => [$paths]];
    $stack = $interpreter->initialStack($definition);
    $instruction = $interpreter->current($definition, $stack);

    expect($instruction?->step['id'])->toBe($pathsId);

    $stack = $interpreter->enterBranches(
        $definition,
        $instruction->stack,
        $paths,
        [$branchA, $branchB],
    );
    $first = $interpreter->current($definition, $stack);
    $stack = $interpreter->advance($first->stack);
    $second = $interpreter->current($definition, $stack);

    expect($first?->step['id'])->toBe($actionA)
        ->and($first?->branchKey)->toBe($branchA)
        ->and($second?->step['id'])->toBe($actionB)
        ->and($second?->branchKey)->toBe($branchB);
});
