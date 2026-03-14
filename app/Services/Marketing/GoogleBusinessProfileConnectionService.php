<?php

namespace App\Services\Marketing;

use App\Models\GoogleBusinessProfileConnection;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GoogleBusinessProfileConnectionService
{
    public const PROVIDER_KEY = 'google_business_profile';

    public function __construct(
        protected GoogleBusinessProfileOAuthService $oauthService,
        protected GoogleBusinessProfileApiService $apiService,
        protected MarketingStorefrontEventLogger $eventLogger,
    ) {
    }

    public function oauthReady(): bool
    {
        return $this->oauthService->enabled();
    }

    public function current(): ?GoogleBusinessProfileConnection
    {
        return GoogleBusinessProfileConnection::query()
            ->with(['locations' => fn ($query) => $query->orderByDesc('is_selected')->orderBy('title')->orderBy('location_id')])
            ->where('provider_key', self::PROVIDER_KEY)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $connection = $this->current();
        $integrationConfig = $this->integrationConfig();
        $lastRun = $connection?->syncRuns()->latest('id')->first();

        return [
            'oauth_ready' => $this->oauthReady(),
            'enabled' => (bool) data_get($integrationConfig, 'google_review_enabled', false),
            'connection' => $connection,
            'connection_status' => (string) ($connection?->connection_status ?: ($this->oauthReady() ? 'disconnected' : 'not_configured')),
            'project_approval_status' => (string) ($connection?->project_approval_status ?: 'unknown'),
            'linked_account_id' => $connection?->linked_account_id,
            'linked_account_display_name' => $connection?->linked_account_display_name,
            'linked_location_id' => $connection?->linked_location_id,
            'linked_location_title' => $connection?->linked_location_title,
            'linked_location_place_id' => $connection?->linked_location_place_id,
            'linked_location_maps_uri' => $connection?->linked_location_maps_uri,
            'granted_scopes' => (array) ($connection?->granted_scopes ?? []),
            'last_sync_at' => $connection?->last_synced_at,
            'last_error_code' => $connection?->last_error_code,
            'last_error_message' => $connection?->last_error_message,
            'last_error_at' => $connection?->last_error_at,
            'locations' => $connection?->locations ?? collect(),
            'last_sync_run' => $lastRun,
            'review_url' => $this->resolveReviewUrl($connection),
        ];
    }

    public function buildConnectUrl(User $user): string
    {
        $state = Str::random(48);
        Cache::store('file')->put($this->stateCacheKey($state), [
            'user_id' => (int) $user->id,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        return $this->oauthService->buildAuthorizeUrl($state);
    }

    /**
     * @return array<string,mixed>
     */
    public function connectFromCallback(string $code, string $state): array
    {
        $cached = Cache::store('file')->pull($this->stateCacheKey($state));
        if (! is_array($cached) || (int) ($cached['user_id'] ?? 0) <= 0) {
            throw new GoogleBusinessProfileException('invalid_oauth_state', 'Google Business connection state expired. Start the connection again.');
        }

        $user = User::query()->find((int) $cached['user_id']);
        if (! $user || ! in_array((string) $user->role, ['admin', 'marketing_manager'], true)) {
            throw new GoogleBusinessProfileException('invalid_connector', 'Only admins or marketing managers can connect Google Business Profile.');
        }

        $tokens = $this->oauthService->exchangeCode($code);
        $connection = GoogleBusinessProfileConnection::query()->firstOrNew(['provider_key' => self::PROVIDER_KEY]);
        $connection->fill([
            'provider_key' => self::PROVIDER_KEY,
            'connection_status' => 'connected',
            'connected_by_user_id' => $user->id,
            'access_token' => $tokens['access_token'],
            'refresh_token' => trim((string) ($tokens['refresh_token'] ?? '')) !== ''
                ? $tokens['refresh_token']
                : $connection->refresh_token,
            'token_type' => $tokens['token_type'],
            'expires_at' => $tokens['expires_at'],
            'granted_scopes' => $tokens['granted_scopes'],
            'connected_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
            'metadata' => array_merge((array) $connection->metadata, [
                'oauth_connected_by' => $user->email,
            ]),
        ]);
        $connection->save();

        $locationResult = $this->refreshAccountsAndLocations($connection);
        $locations = $locationResult['locations'];
        $autoLinked = false;
        if ($locations->count() === 1) {
            $this->selectLocation($locations->firstOrFail());
            $autoLinked = true;
        }

        return [
            'connection' => $connection->fresh(['locations']),
            'locations' => $locations,
            'auto_linked' => $autoLinked,
            'connector' => $user,
        ];
    }

    /**
     * @return array{connection:GoogleBusinessProfileConnection,locations:\Illuminate\Support\Collection<int,GoogleBusinessProfileLocation>}
     */
    public function refreshAccountsAndLocations(?GoogleBusinessProfileConnection $connection = null): array
    {
        $connection = $connection ?: $this->current();
        if (! $connection) {
            throw new GoogleBusinessProfileException('not_connected', 'Google Business Profile is not connected yet.');
        }

        try {
            $accounts = $this->apiService->fetchAccounts($connection);
        } catch (GoogleBusinessProfileException $exception) {
            $this->markError($connection, $exception);
            throw $exception;
        }

        if ($accounts === []) {
            $this->markError($connection, new GoogleBusinessProfileException('no_accounts_found', 'No Google Business Profile accounts were returned for this Google user.'));
            throw new GoogleBusinessProfileException('no_accounts_found', 'No Google Business Profile accounts were returned for this Google user.');
        }

        $rows = [];
        foreach ($accounts as $account) {
            $accountName = trim((string) ($account['name'] ?? ''));
            if ($accountName === '') {
                continue;
            }

            try {
                $locations = $this->apiService->fetchLocations($connection, $accountName);
            } catch (GoogleBusinessProfileException $exception) {
                $this->markError($connection, $exception);
                throw $exception;
            }

            foreach ($locations as $location) {
                $rows[] = $this->upsertLocation($connection, $account, $location);
            }
        }

        $connection->forceFill([
            'google_account_label' => $this->accountLabel($accounts[0] ?? []),
            'project_approval_status' => 'approved',
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return [
            'connection' => $connection->fresh(['locations']),
            'locations' => $connection->locations()->orderByDesc('is_selected')->orderBy('title')->get(),
        ];
    }

    public function selectLocation(GoogleBusinessProfileLocation $location): GoogleBusinessProfileConnection
    {
        $connection = $location->connection()->firstOrFail();

        $connection->locations()->update([
            'is_selected' => false,
            'selected_at' => null,
        ]);

        $location->forceFill([
            'is_selected' => true,
            'selected_at' => now(),
        ])->save();

        $connection->forceFill([
            'linked_account_name' => $location->account_name,
            'linked_account_id' => $location->account_id,
            'linked_account_display_name' => $location->account_display_name,
            'linked_location_name' => $location->location_name,
            'linked_location_id' => $location->location_id,
            'linked_location_title' => $location->title,
            'linked_location_place_id' => $location->place_id,
            'linked_location_maps_uri' => $location->maps_uri,
            'connection_status' => 'connected',
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        $integrationConfig = $this->integrationConfig();
        $integrationConfig['google_business_location_id'] = $location->location_id;
        if (blank($integrationConfig['google_review_url'] ?? null)) {
            $generatedReviewUrl = $this->reviewUrlForLocation($location);
            if ($generatedReviewUrl !== null) {
                $integrationConfig['google_review_url'] = $generatedReviewUrl;
            }
        }
        $this->saveIntegrationConfig($integrationConfig);

        return $connection->fresh(['locations']);
    }

    public function disconnect(): void
    {
        $connection = $this->current();
        if (! $connection) {
            return;
        }

        $connection->locations()->update([
            'is_selected' => false,
            'selected_at' => null,
        ]);

        $connection->forceFill([
            'connection_status' => 'disconnected',
            'access_token' => null,
            'refresh_token' => null,
            'token_type' => null,
            'expires_at' => null,
            'granted_scopes' => [],
            'linked_account_name' => null,
            'linked_account_id' => null,
            'linked_account_display_name' => null,
            'linked_location_name' => null,
            'linked_location_id' => null,
            'linked_location_title' => null,
            'linked_location_place_id' => null,
            'linked_location_maps_uri' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        $integrationConfig = $this->integrationConfig();
        $integrationConfig['google_business_location_id'] = null;
        $this->saveIntegrationConfig($integrationConfig);
    }

    /**
     * @return array{ok:bool,review_url:?string,location_id:?string,location_title:?string}
     */
    public function startReview(MarketingProfile $profile, string $requestKey, string $surface = 'candle_cash_central'): array
    {
        $connection = $this->current();
        $reviewUrl = $this->resolveReviewUrl($connection);
        if (! $connection || ! $connection->linked_location_id || ! $reviewUrl) {
            throw new GoogleBusinessProfileException('google_review_not_ready', 'Google review matching is not fully configured yet.');
        }

        $fullName = trim((string) $profile->display_name ?: trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')));

        $this->eventLogger->log('google_business_review_start', [
            'status' => 'ok',
            'profile' => $profile,
            'request_key' => $requestKey,
            'dedupe_key' => $requestKey,
            'source_surface' => $surface,
            'source_type' => 'shopify_widget_google_business_review',
            'source_id' => $connection->linked_location_id,
            'meta' => [
                'expected_reviewer_name' => $fullName !== '' ? $fullName : null,
                'location_id' => $connection->linked_location_id,
                'location_title' => $connection->linked_location_title,
            ],
            'resolution_status' => 'open',
        ]);

        return [
            'ok' => true,
            'review_url' => $reviewUrl,
            'location_id' => $connection->linked_location_id,
            'location_title' => $connection->linked_location_title,
        ];
    }

    public function resolveReviewUrl(?GoogleBusinessProfileConnection $connection = null): ?string
    {
        $integrationConfig = $this->integrationConfig();
        $manual = trim((string) data_get($integrationConfig, 'google_review_url', ''));
        if ($manual !== '') {
            return $manual;
        }

        $connection = $connection ?: $this->current();
        if (! $connection) {
            return null;
        }

        if (filled($connection->linked_location_place_id)) {
            return 'https://search.google.com/local/writereview?placeid=' . rawurlencode((string) $connection->linked_location_place_id);
        }

        if (filled($connection->linked_location_maps_uri)) {
            return (string) $connection->linked_location_maps_uri;
        }

        return null;
    }

    public function markSynced(GoogleBusinessProfileConnection $connection): void
    {
        $connection->forceFill([
            'last_synced_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
            'project_approval_status' => 'approved',
        ])->save();
    }

    public function markError(GoogleBusinessProfileConnection $connection, GoogleBusinessProfileException $exception): void
    {
        $connection->forceFill([
            'connection_status' => $exception->errorCode === 'authorization_revoked' ? 'action_required' : $connection->connection_status,
            'project_approval_status' => $exception->errorCode === 'project_not_approved_or_service_disabled' ? 'not_approved' : $connection->project_approval_status,
            'last_error_code' => $exception->errorCode,
            'last_error_message' => $exception->getMessage(),
            'last_error_at' => now(),
        ])->save();
    }

    protected function stateCacheKey(string $state): string
    {
        return 'google_gbp_oauth_state_' . $state;
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $location
     */
    protected function upsertLocation(GoogleBusinessProfileConnection $connection, array $account, array $location): GoogleBusinessProfileLocation
    {
        $accountName = trim((string) ($account['name'] ?? ''));
        $locationName = trim((string) ($location['name'] ?? ''));
        $metadata = (array) ($location['metadata'] ?? []);

        $row = GoogleBusinessProfileLocation::query()->updateOrCreate(
            [
                'google_business_profile_connection_id' => $connection->id,
                'location_name' => $locationName,
            ],
            [
                'account_name' => $accountName,
                'account_id' => $this->resourceId($accountName),
                'account_display_name' => $this->accountLabel($account),
                'location_id' => $this->resourceId($locationName),
                'title' => trim((string) ($location['title'] ?? '')) ?: null,
                'store_code' => trim((string) ($location['storeCode'] ?? '')) ?: null,
                'website_uri' => trim((string) ($location['websiteUri'] ?? '')) ?: null,
                'place_id' => trim((string) Arr::get($metadata, 'placeId', '')) ?: null,
                'maps_uri' => trim((string) Arr::get($metadata, 'mapsUri', '')) ?: null,
                'storefront_address' => is_array($location['storefrontAddress'] ?? null) ? $location['storefrontAddress'] : null,
                'last_seen_at' => now(),
                'metadata' => $location,
            ]
        );

        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $accounts
     */
    protected function accountLabel(array $account): string
    {
        return trim((string) ($account['accountName'] ?? $account['name'] ?? ''));
    }

    protected function resourceId(?string $resourceName): ?string
    {
        $resourceName = trim((string) $resourceName);

        return $resourceName !== '' ? Str::afterLast($resourceName, '/') : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function integrationConfig(): array
    {
        return (array) optional(MarketingSetting::query()->where('key', 'candle_cash_integration_config')->first())->value;
    }

    /**
     * @param array<string,mixed> $value
     */
    protected function saveIntegrationConfig(array $value): void
    {
        MarketingSetting::query()->updateOrCreate(
            ['key' => 'candle_cash_integration_config'],
            [
                'value' => $value,
                'description' => 'Integration and verification settings for automatic-first Candle Cash tasks.',
            ]
        );
    }

    protected function reviewUrlForLocation(GoogleBusinessProfileLocation $location): ?string
    {
        if (filled($location->place_id)) {
            return 'https://search.google.com/local/writereview?placeid=' . rawurlencode((string) $location->place_id);
        }

        if (filled($location->maps_uri)) {
            return (string) $location->maps_uri;
        }

        return null;
    }
}
