<?php

namespace App\Services\Mobile;

use RuntimeException;

class ModernForestryMobileCustomerAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $authCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'customer_auth_not_configured',
            'Modern Forestry customer login is not configured.',
            503
        );
    }

    public static function invalidCallback(): self
    {
        return new self(
            'customer_auth_invalid_callback',
            'The Shopify sign-in response was incomplete. Please try again.',
            422
        );
    }

    public static function exchangeFailed(int $status = 502, array $context = []): self
    {
        return new self(
            'customer_auth_exchange_failed',
            'Shopify could not finish sign-in. Please try again.',
            $status,
            $context
        );
    }

    public static function validationFailed(): self
    {
        return new self(
            'customer_auth_validation_failed',
            'Shopify sign-in finished, but the customer session could not be verified.',
            401
        );
    }
}
