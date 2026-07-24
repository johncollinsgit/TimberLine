<?php

namespace App\Services\Automation\V2;

use RuntimeException;

/**
 * The provider explicitly rejected an action before reporting success. This is
 * distinct from an uncertain transport failure, where replay could duplicate a
 * customer-facing write.
 */
class WorkflowActionDispatchFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
