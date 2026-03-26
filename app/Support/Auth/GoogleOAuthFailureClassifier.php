<?php

namespace App\Support\Auth;

use Throwable;

class GoogleOAuthFailureClassifier
{
    public const INVALID_CLIENT = 'invalid_client';
    public const INVALID_GRANT = 'invalid_grant';
    public const REDIRECT_URI_MISMATCH = 'redirect_uri_mismatch';
    public const STATE_ERROR = 'state_error';
    public const UNKNOWN_OAUTH_FAILURE = 'unknown_oauth_failure';

    public static function classify(
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?Throwable $exception = null,
        bool $stateError = false,
    ): string {
        if ($stateError) {
            return self::STATE_ERROR;
        }

        $haystacks = array_filter([
            self::normalize($errorCode),
            self::normalize($errorMessage),
            self::normalize($exception?->getMessage()),
        ], static fn (?string $value): bool => filled($value));

        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, self::REDIRECT_URI_MISMATCH)) {
                return self::REDIRECT_URI_MISMATCH;
            }

            if (str_contains($haystack, self::INVALID_CLIENT)) {
                return self::INVALID_CLIENT;
            }

            if (str_contains($haystack, self::INVALID_GRANT)) {
                return self::INVALID_GRANT;
            }
        }

        return self::UNKNOWN_OAUTH_FAILURE;
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}

