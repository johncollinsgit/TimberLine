<?php

namespace App\Services\Automation\V2\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ControlResult
{
    public const CONTINUE = 'continue';

    public const STOP = 'stop';

    public const DELAY = 'delay';

    public const BRANCHES = 'branches';

    /**
     * @param  array<int,string>  $branchIds
     * @param  array<string,mixed>  $output
     * @param  array<string,mixed>  $summary
     */
    public function __construct(
        public string $outcome,
        public array $branchIds = [],
        public ?CarbonImmutable $availableAt = null,
        public array $output = [],
        public array $summary = [],
    ) {
        if (! in_array($outcome, [self::CONTINUE, self::STOP, self::DELAY, self::BRANCHES], true)) {
            throw new InvalidArgumentException('Unsupported control outcome.');
        }

        if ($outcome === self::DELAY && $availableAt === null) {
            throw new InvalidArgumentException('A delayed control result must include availableAt.');
        }
    }

    public static function continue(array $output = [], array $summary = []): self
    {
        return new self(self::CONTINUE, output: $output, summary: $summary);
    }

    public static function stop(array $summary = []): self
    {
        return new self(self::STOP, summary: $summary);
    }

    public static function delay(
        CarbonImmutable $availableAt,
        array $summary = [],
        array $output = [],
    ): self {
        return new self(
            self::DELAY,
            availableAt: $availableAt,
            output: $output !== [] ? $output : ['resume_at' => $availableAt->toIso8601String()],
            summary: $summary,
        );
    }

    /**
     * @param  array<int,string>  $branchIds
     */
    public static function branches(array $branchIds, array $summary = []): self
    {
        $branchIds = array_values($branchIds);

        return new self(
            self::BRANCHES,
            branchIds: $branchIds,
            output: ['matched_branch_keys' => $branchIds],
            summary: $summary,
        );
    }
}
