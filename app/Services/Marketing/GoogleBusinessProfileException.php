<?php

namespace App\Services\Marketing;

use RuntimeException;

class GoogleBusinessProfileException extends RuntimeException
{
    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        int $status = 0
    ) {
        parent::__construct($message, $status);
    }
}
