<?php

namespace App\Services\Automation\V2\Data;

final readonly class InterpreterInstruction
{
    /**
     * @param  array<string,mixed>  $step
     * @param  array<int,array<string,mixed>>  $stack
     */
    public function __construct(
        public array $step,
        public array $stack,
        public ?string $parentStepId = null,
        public ?string $branchKey = null,
        public bool $insideBranch = false,
    ) {}
}
