<?php

namespace App\Services\Automation\V2\Data;

final readonly class ActionResult
{
    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,mixed>  $summary
     */
    public function __construct(
        public array $output = [],
        public array $summary = [],
        public ?string $externalId = null,
        public string $status = 'succeeded',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'external_id' => $this->externalId,
            'output' => $this->output,
            'summary' => $this->summary,
        ];
    }
}
