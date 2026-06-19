<?php

namespace App\Services\Mobile;

use RuntimeException;

class ModernForestryMobileCheckoutException extends RuntimeException
{
    public function __construct(
        protected string $publicCode,
        string $message,
        protected int $status = 422
    ) {
        parent::__construct($message);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
