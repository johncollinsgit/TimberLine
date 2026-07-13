<?php

namespace App\Services\Marketing;

use RuntimeException;

class CustomerMergeException extends RuntimeException
{
    public function __construct(string $message, protected string $publicCode = 'merge_failed')
    {
        parent::__construct($message);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }
}
