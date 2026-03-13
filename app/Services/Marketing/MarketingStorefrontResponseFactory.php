<?php

namespace App\Services\Marketing;

use Illuminate\Http\JsonResponse;

class MarketingStorefrontResponseFactory
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     * @param array<int,string> $states
     */
    public function success(array $data = [], array $meta = [], array $states = []): JsonResponse
    {
        $stateList = $this->normalizeStates($states);
        if ($stateList !== []) {
            $meta['states'] = $stateList;
        }

        return response()->json([
            'ok' => true,
            'version' => $this->version(),
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    /**
     * @param array<string,mixed> $details
     * @param array<int,string> $states
     * @param array<int,string> $recoveryStates
     */
    public function error(
        string $code,
        string $message,
        int $status = 422,
        array $details = [],
        array $states = [],
        array $recoveryStates = []
    ): JsonResponse {
        $stateList = $this->normalizeStates($states);
        $recoveryList = $this->normalizeStates($recoveryStates);

        return response()->json([
            'ok' => false,
            'version' => $this->version(),
            'error' => [
                'code' => trim($code) !== '' ? trim($code) : 'storefront_error',
                'message' => trim($message) !== '' ? trim($message) : 'Storefront request failed.',
                'details' => $details,
                'states' => $stateList,
                'recovery_states' => $recoveryList,
            ],
        ], $status);
    }

    protected function version(): string
    {
        $version = trim((string) config('marketing.shopify.contract_version', 'v1'));

        return $version !== '' ? $version : 'v1';
    }

    /**
     * @param array<int,string> $states
     * @return array<int,string>
     */
    protected function normalizeStates(array $states): array
    {
        return collect($states)
            ->map(fn ($value): string => trim(strtolower((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}

