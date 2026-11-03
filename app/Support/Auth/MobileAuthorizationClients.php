<?php

namespace App\Support\Auth;

use Illuminate\Validation\ValidationException;

final class MobileAuthorizationClients
{
    public const PAYMENTS_CLIENT = 'evergrove-payments-ios';

    public const PAYMENTS_REDIRECT = 'evergrovepayments://auth/callback';

    public const POS_CLIENT = 'everbranch-pos-ios';

    public const POS_REDIRECT = 'everbranchpos://auth/callback';

    public static function assertAllowed(string $client, string $redirect): void
    {
        $legacy = $client === 'everbranch-mobile' && in_array($redirect, ['everbranch://auth/callback', 'https://app.theeverbranch.com/field/'], true);
        $payments = self::isPayments($client)
            && $client === self::PAYMENTS_CLIENT
            && $redirect === self::PAYMENTS_REDIRECT
            && config('mobile_authorization.evergrove_payments_enabled') === true;
        $pos = $client === self::POS_CLIENT && $redirect === self::POS_REDIRECT
            && config('pos_live.native_client_enabled') === true;

        if ((config('enterprise.runtime') === true && ! $pos) || (! $legacy && ! $payments && ! $pos)) {
            throw ValidationException::withMessages(['client_id' => 'This mobile client and callback pair is unavailable.']);
        }
    }

    public static function isPayments(string $client): bool
    {
        return in_array($client, [self::PAYMENTS_CLIENT, self::POS_CLIENT], true);
    }
}
