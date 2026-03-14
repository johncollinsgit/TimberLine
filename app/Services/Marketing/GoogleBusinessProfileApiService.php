<?php

namespace App\Services\Marketing;

use App\Models\GoogleBusinessProfileConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleBusinessProfileApiService
{
    public function __construct(
        protected GoogleBusinessProfileOAuthService $oauthService
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchAccounts(GoogleBusinessProfileConnection $connection): array
    {
        $accounts = [];
        $pageToken = null;

        do {
            $response = $this->request($connection)->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', array_filter([
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ]));

            $payload = $this->decodeResponse($response, 'fetch_accounts');
            $accounts = array_merge($accounts, array_values(array_filter((array) ($payload['accounts'] ?? []), 'is_array')));
            $pageToken = trim((string) ($payload['nextPageToken'] ?? '')) ?: null;
        } while ($pageToken !== null);

        return $accounts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchLocations(GoogleBusinessProfileConnection $connection, string $accountName): array
    {
        $locations = [];
        $pageToken = null;

        do {
            $response = $this->request($connection)->get('https://mybusinessbusinessinformation.googleapis.com/v1/' . trim($accountName) . '/locations', array_filter([
                'pageSize' => 100,
                'pageToken' => $pageToken,
                'readMask' => 'name,title,storeCode,websiteUri,languageCode,storefrontAddress,metadata',
            ]));

            $payload = $this->decodeResponse($response, 'fetch_locations');
            $locations = array_merge($locations, array_values(array_filter((array) ($payload['locations'] ?? []), 'is_array')));
            $pageToken = trim((string) ($payload['nextPageToken'] ?? '')) ?: null;
        } while ($pageToken !== null);

        return $locations;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchReviews(GoogleBusinessProfileConnection $connection, string $accountId, string $locationId, ?string $pageToken = null): array
    {
        $response = $this->request($connection)->get(sprintf(
            'https://mybusiness.googleapis.com/v4/accounts/%s/locations/%s/reviews',
            rawurlencode(trim($accountId)),
            rawurlencode(trim($locationId))
        ), array_filter([
            'pageSize' => 50,
            'pageToken' => $pageToken,
            'orderBy' => 'updateTime desc',
        ]));

        $payload = $this->decodeResponse($response, 'fetch_reviews');

        return [
            'reviews' => array_values(array_filter((array) ($payload['reviews'] ?? []), 'is_array')),
            'nextPageToken' => trim((string) ($payload['nextPageToken'] ?? '')) ?: null,
        ];
    }

    protected function request(GoogleBusinessProfileConnection $connection): PendingRequest
    {
        $connection = $this->refreshTokenIfNeeded($connection);

        $token = trim((string) $connection->access_token);
        if ($token === '') {
            throw new GoogleBusinessProfileException('missing_access_token', 'Google Business Profile is not connected yet.');
        }

        return Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->retry(1, 250, throw: false);
    }

    protected function refreshTokenIfNeeded(GoogleBusinessProfileConnection $connection): GoogleBusinessProfileConnection
    {
        if ($connection->access_token && $connection->expires_at && $connection->expires_at->subMinutes(5)->isFuture()) {
            return $connection;
        }

        $refreshToken = trim((string) $connection->refresh_token);
        if ($refreshToken === '') {
            throw new GoogleBusinessProfileException('missing_refresh_token', 'Google Business Profile needs to be reconnected because the refresh token is missing.');
        }

        try {
            $token = $this->oauthService->refreshAccessToken($refreshToken);
        } catch (GoogleBusinessProfileException $exception) {
            if (in_array($exception->errorCode, ['oauth_invalid_grant', 'oauth_token_exchange_failed'], true)) {
                throw new GoogleBusinessProfileException('authorization_revoked', 'Google Business authorization has expired or was revoked. Reconnect the account to continue syncing.', $exception->context, $exception->getCode());
            }

            throw $exception;
        }

        $connection->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => trim((string) ($token['refresh_token'] ?? '')) !== '' ? $token['refresh_token'] : $refreshToken,
            'token_type' => $token['token_type'],
            'expires_at' => $token['expires_at'],
            'granted_scopes' => $token['granted_scopes'],
            'connection_status' => 'connected',
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $connection->fresh();
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeResponse(Response $response, string $operation): array
    {
        $payload = $response->json();
        $body = is_array($payload) ? $payload : [];

        if ($response->successful()) {
            return $body;
        }

        throw $this->translateError($response, $body, $operation);
    }

    /**
     * @param array<string,mixed> $body
     */
    protected function translateError(Response $response, array $body, string $operation): GoogleBusinessProfileException
    {
        $message = trim((string) Arr::get($body, 'error.message', Arr::get($body, 'message', 'Google Business Profile request failed.')));
        $statusText = strtoupper(trim((string) Arr::get($body, 'error.status', '')));
        $combined = Str::lower($message . ' ' . $statusText . ' ' . json_encode(Arr::get($body, 'error.details', [])));

        $errorCode = match (true) {
            $response->status() === 401,
            str_contains($combined, 'invalid_grant'),
            str_contains($combined, 'unauthenticated') => 'authorization_revoked',
            str_contains($combined, 'insufficient authentication scopes'),
            str_contains($combined, 'request had insufficient authentication scopes') => 'insufficient_scope',
            str_contains($combined, 'api has not been used'),
            str_contains($combined, 'service_disabled'),
            str_contains($combined, 'accessnotconfigured'),
            str_contains($combined, 'google my business api has not been used'),
            str_contains($combined, 'business profile api has not been used'),
            str_contains($combined, 'quota'),
            str_contains($combined, '0 qpm'),
            str_contains($combined, 'per minute is 0'),
            str_contains($combined, 'permission_denied') => 'project_not_approved_or_service_disabled',
            str_contains($combined, 'not found') && $operation === 'fetch_reviews' => 'project_not_approved_or_service_disabled',
            default => 'google_business_api_error',
        };

        $friendly = match ($errorCode) {
            'authorization_revoked' => 'Google Business authorization expired or was revoked. Reconnect the account and try again.',
            'insufficient_scope' => 'Google did not grant the required Business Profile scope. Reconnect with Business Profile access.',
            'project_not_approved_or_service_disabled' => 'The Google project is not approved or enabled for the Business Profile review APIs yet.',
            default => $message !== '' ? $message : 'Google Business Profile request failed.',
        };

        return new GoogleBusinessProfileException($errorCode, $friendly, [
            'operation' => $operation,
            'response' => $body,
            'http_status' => $response->status(),
        ], $response->status());
    }
}
