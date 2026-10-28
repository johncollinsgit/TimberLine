<?php

namespace App\Jobs\Wholesale;

use App\Services\Wholesale\Labels\WholesaleLabelProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWholesaleLabel implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $revisionId) {}

    public function handle(WholesaleLabelProcessor $processor): void
    {
        $processor->process($this->revisionId);
    }
}
