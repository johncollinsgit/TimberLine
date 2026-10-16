<?php

namespace App\Services\Subscriptions;

/** Deliberately contains no provider response, request, headers or previous exception. */
class SubscriptionProviderReadException extends \RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('Subscription read could not be completed: '.$failureCode.'.');
    }
}
