<?php

namespace App\Support\Auth;

use Illuminate\Validation\ValidationException;

final class MobileAuthorizationClients
{
    public const PAYMENTS_CLIENT = 'evergrove-payments-ios';

    public const PAYMENTS_REDIRECT = 'evergrovepayments://auth/callback';

    public static function assertAllowed(string $client, string $redirect): void
    {
        $legacy = $client === 'everbranch-mobile' && $redirect === 'everbranch://auth/callback';
        $payments = self::isPayments($client)
            && $redirect === self::PAYMENTS_REDIRECT
            && config('mobile_authorization.evergrove_payments_enabled') === true;

        if (! $legacy && ! $payments) {
            throw ValidationException::withMessages(['client_id' => 'This mobile client and callback pair is unavailable.']);
        }
    }

    public static function isPayments(string $client): bool
    {
        return $client === self::PAYMENTS_CLIENT;
    }
}
