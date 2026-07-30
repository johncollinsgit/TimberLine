<?php

namespace App\Services\Automation\V2\Data;

use Carbon\CarbonImmutable;

final readonly class InterpreterResult
{
    /**
     * @param  array<string,mixed>  $summary
     */
    public function __construct(
        public string $status,
        public array $summary = [],
        public ?CarbonImmutable $availableAt = null,
    ) {}
}
