<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionPilotCoverageException extends HttpException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(409, $message);
    }
}
