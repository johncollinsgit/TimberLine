<?php

namespace App\Support\Marketing;

use App\Services\Marketing\MarketingStorefrontResponseFactory;
use Illuminate\Http\JsonResponse;

class MarketingStorefrontContract
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     * @param array<int,string> $states
     */
    public static function success(array $data = [], array $meta = [], array $states = []): JsonResponse
    {
        return app(MarketingStorefrontResponseFactory::class)->success($data, $meta, $states);
    }

    /**
     * @param array<string,mixed> $details
     * @param array<int,string> $states
     * @param array<int,string> $recoveryStates
     */
    public static function error(
        string $code,
        string $message,
        int $status = 422,
        array $details = [],
        array $states = [],
        array $recoveryStates = []
    ): JsonResponse
    {
        return app(MarketingStorefrontResponseFactory::class)->error(
            code: $code,
            message: $message,
            status: $status,
            details: $details,
            states: $states,
            recoveryStates: $recoveryStates
        );
    }
}
