<?php

namespace App\Services\Automation;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendarWorkflowConnectionService
{
    public const WORKFLOW_KEY = 'asana_to_google_calendar';

    public function __construct(
        protected TenantWorkflowAutomationSettingsService $workflowSettingsService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function status(int $tenantId, string $workflowKey = self::WORKFLOW_KEY): array
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $redirectUri = $this->redirectUri();
        $oauthReady = $credentials['google_calendar_client_id'] !== null
            && $credentials['google_calendar_client_secret'] !== null
            && $redirectUri !== null;
        $connected = $oauthReady && $credentials['google_calendar_refresh_token'] !== null;

        $calendars = [];
        $error = null;

        if ($connected) {
            try {
                $calendars = $this->calendarOptions($tenantId, $workflowKey);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $selectedCalendarId = $this->nullableString(data_get(
            $this->workflowSettingsService->forTenant($tenantId, $workflowKey),
            'action.calendar_id'
        ));

        $selectedCalendar = collect($calendars)->first(
            fn (array $calendar): bool => trim((string) ($calendar['id'] ?? '')) === $selectedCalendarId
        );

        return [
            'oauth_ready' => $oauthReady,
            'connected' => $connected,
            'redirect_uri' => $redirectUri,
            'calendars' => $calendars,
            'selected_calendar_id' => $selectedCalendarId,
            'selected_calendar_summary' => is_array($selectedCalendar) ? ($selectedCalendar['summary'] ?? null) : null,
            'error' => $error,
            'credential_sources' => [
                'client_id' => $credentials['sources']['google_calendar_client_id'] ?? 'missing',
                'client_secret' => $credentials['sources']['google_calendar_client_secret'] ?? 'missing',
                'refresh_token' => $credentials['sources']['google_calendar_refresh_token'] ?? 'missing',
            ],
        ];
    }

    public function buildConnectUrl(int $tenantId, User $user, string $workflowKey = self::WORKFLOW_KEY): string
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $clientId = $this->nullableString($credentials['google_calendar_client_id'] ?? null);
        $clientSecret = $this->nullableString($credentials['google_calendar_client_secret'] ?? null);
        $redirectUri = $this->redirectUri();

        if ($clientId === null || $clientSecret === null || $redirectUri === null) {
            throw new AutomationWorkflowException('Google Calendar OAuth needs a client ID, client secret, and callback URL before you can connect it.');
        }

        $state = Str::random(48);
        $this->stateCache()->put($this->stateCacheKey($state), [
            'user_id' => (int) $user->id,
            'tenant_id' => $tenantId,
            'workflow_key' => $workflowKey,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @return array<string,mixed>
     */
    public function connectFromCallback(string $code, string $state): array
    {
        $cached = $this->stateCache()->pull($this->stateCacheKey($state));
        if (! is_array($cached) || (int) ($cached['tenant_id'] ?? 0) <= 0 || trim((string) ($cached['workflow_key'] ?? '')) === '') {
            throw new AutomationWorkflowException('Google Calendar connection state expired. Start the connection again.');
        }

        $tenantId = (int) $cached['tenant_id'];
        $workflowKey = $this->normalizeWorkflowKey((string) $cached['workflow_key']);

        $user = User::query()->find((int) ($cached['user_id'] ?? 0));
        if (! $user || ! in_array((string) $user->role, ['admin', 'marketing_manager'], true)) {
            throw new AutomationWorkflowException('Only admins or marketing managers can connect Google Calendar.');
        }

        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $token = $this->exchangeCode(
            code: trim($code),
            clientId: $credentials['google_calendar_client_id'] ?? null,
            clientSecret: $credentials['google_calendar_client_secret'] ?? null,
        );

        $refreshToken = $this->nullableString((string) ($token['refresh_token'] ?? ''))
            ?? $this->nullableString($credentials['google_calendar_refresh_token'] ?? null);

        if ($refreshToken === null) {
            throw new AutomationWorkflowException('Google did not return a refresh token. Reconnect and make sure consent is granted again.');
        }

        $this->workflowSettingsService->mergeStoredValue($tenantId, $workflowKey, [
            'credentials' => [
                'google_calendar_refresh_token_encrypted' => Crypt::encryptString($refreshToken),
            ],
            'google_calendar_oauth' => [
                'connected_at' => now()->toIso8601String(),
                'connected_by_user_id' => $user->id,
                'granted_scopes' => $token['granted_scopes'] ?? $this->scopes(),
                'token_type' => $token['token_type'] ?? 'Bearer',
            ],
        ]);

        $calendars = $this->calendarOptions($tenantId, $workflowKey, true);
        $stored = $this->workflowSettingsService->storedValueForTenant($tenantId, $workflowKey);
        $currentCalendarId = $this->nullableString(data_get($stored, 'action.calendar_id'));
        $autoSelected = false;

        if ($currentCalendarId === null && count($calendars) === 1) {
            $this->workflowSettingsService->mergeStoredValue($tenantId, $workflowKey, [
                'action' => [
                    'calendar_id' => (string) ($calendars[0]['id'] ?? ''),
                ],
            ]);
            $autoSelected = true;
        }

        return [
            'tenant_id' => $tenantId,
            'workflow_key' => $workflowKey,
            'calendars' => $calendars,
            'auto_selected' => $autoSelected,
        ];
    }

    public function disconnect(int $tenantId, string $workflowKey = self::WORKFLOW_KEY): void
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);

        $this->workflowSettingsService->mergeStoredValue($tenantId, $workflowKey, [
            'credentials' => [
                'google_calendar_refresh_token_encrypted' => null,
            ],
            'google_calendar_oauth' => [
                'connected_at' => null,
                'connected_by_user_id' => null,
                'granted_scopes' => [],
                'token_type' => null,
            ],
        ]);

        $this->calendarCache()->forget($this->calendarCacheKey($tenantId, $workflowKey));
    }

    /**
     * @return array<int,array{id:string,summary:string,description:?string,time_zone:?string,primary:bool,access_role:string}>
     */
    public function calendarOptions(int $tenantId, string $workflowKey = self::WORKFLOW_KEY, bool $forceRefresh = false): array
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $cacheKey = $this->calendarCacheKey($tenantId, $workflowKey);

        if (! $forceRefresh) {
            $cached = $this->calendarCache()->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $accessToken = $this->refreshAccessToken(
            clientId: $credentials['google_calendar_client_id'] ?? null,
            clientSecret: $credentials['google_calendar_client_secret'] ?? null,
            refreshToken: $credentials['google_calendar_refresh_token'] ?? null,
        );

        $options = $this->fetchCalendarOptions($accessToken);
        $this->calendarCache()->put($cacheKey, $options, now()->addMinutes(15));

        return $options;
    }

    /**
     * @return array<int,string>
     */
    protected function scopes(): array
    {
        $configured = explode(',', (string) config('services.google_calendar.oauth_scopes', 'https://www.googleapis.com/auth/calendar'));

        return array_values(array_unique(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            $configured
        ))));
    }

    protected function redirectUri(): ?string
    {
        return $this->nullableString(config('services.google_calendar.redirect_uri'));
    }

    /**
     * @return array<string,mixed>
     */
    protected function exchangeCode(string $code, ?string $clientId, ?string $clientSecret): array
    {
        $clientId = $this->nullableString($clientId);
        $clientSecret = $this->nullableString($clientSecret);
        $redirectUri = $this->redirectUri();

        if ($clientId === null || $clientSecret === null || $redirectUri === null) {
            throw new AutomationWorkflowException('Google Calendar OAuth is not configured yet.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        return $this->decodeTokenResponse($response->status(), $response->json());
    }

    protected function refreshAccessToken(?string $clientId, ?string $clientSecret, ?string $refreshToken): string
    {
        $clientId = $this->nullableString($clientId);
        $clientSecret = $this->nullableString($clientSecret);
        $refreshToken = $this->nullableString($refreshToken);

        if ($clientId === null || $clientSecret === null || $refreshToken === null) {
            throw new AutomationWorkflowException('Google Calendar needs a client ID, client secret, and refresh token before calendars can be loaded.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://oauth2.googleapis.com/token', [
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        $token = $this->decodeTokenResponse($response->status(), $response->json());
        $accessToken = $this->nullableString($token['access_token'] ?? null);

        if ($accessToken === null) {
            throw new AutomationWorkflowException('Google OAuth token response did not include an access token.');
        }

        return $accessToken;
    }

    /**
     * @return array<int,array{id:string,summary:string,description:?string,time_zone:?string,primary:bool,access_role:string}>
     */
    protected function fetchCalendarOptions(string $accessToken): array
    {
        $items = [];
        $pageToken = null;

        do {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList', array_filter([
                    'minAccessRole' => 'writer',
                    'maxResults' => 250,
                    'showHidden' => 'false',
                    'pageToken' => $pageToken,
                ]));

            $payload = $response->json();
            $json = is_array($payload) ? $payload : [];

            if (! $response->successful()) {
                $message = trim((string) Arr::get($json, 'error.message', Arr::get($json, 'error', 'Google Calendar list request failed.')));
                throw new AutomationWorkflowException($message !== '' ? $message : 'Google Calendar list request failed.');
            }

            foreach ((array) ($json['items'] ?? []) as $calendar) {
                if (! is_array($calendar)) {
                    continue;
                }

                $id = trim((string) ($calendar['id'] ?? ''));
                $summary = trim((string) ($calendar['summary'] ?? ''));
                if ($id === '' || $summary === '') {
                    continue;
                }

                $items[] = [
                    'id' => $id,
                    'summary' => $summary,
                    'description' => $this->nullableString($calendar['description'] ?? null),
                    'time_zone' => $this->nullableString($calendar['timeZone'] ?? null),
                    'primary' => (bool) ($calendar['primary'] ?? false),
                    'access_role' => trim((string) ($calendar['accessRole'] ?? 'writer')) ?: 'writer',
                ];
            }

            $pageToken = $this->nullableString($json['nextPageToken'] ?? null);
        } while ($pageToken !== null);

        usort($items, static function (array $left, array $right): int {
            $leftOrder = [! ($left['primary'] ?? false), strtolower((string) ($left['summary'] ?? ''))];
            $rightOrder = [! ($right['primary'] ?? false), strtolower((string) ($right['summary'] ?? ''))];

            return $leftOrder <=> $rightOrder;
        });

        return $items;
    }

    /**
     * @param  mixed  $payload
     * @return array<string,mixed>
     */
    protected function decodeTokenResponse(int $status, mixed $payload): array
    {
        $json = is_array($payload) ? $payload : [];

        if ($status >= 200 && $status < 300) {
            return [
                'access_token' => $this->nullableString($json['access_token'] ?? null),
                'refresh_token' => $this->nullableString($json['refresh_token'] ?? null),
                'token_type' => $this->nullableString($json['token_type'] ?? null) ?? 'Bearer',
                'granted_scopes' => $this->nullableString($json['scope'] ?? null) !== null
                    ? array_values(array_filter(preg_split('/\s+/', (string) $json['scope']) ?: []))
                    : $this->scopes(),
            ];
        }

        $message = trim((string) Arr::get($json, 'error_description', Arr::get($json, 'error.message', Arr::get($json, 'error', 'Google OAuth request failed.'))));

        throw new AutomationWorkflowException($message !== '' ? $message : 'Google OAuth request failed.');
    }

    protected function stateCacheKey(string $state): string
    {
        return 'google_calendar_workflow_oauth_state:'.$state;
    }

    protected function calendarCacheKey(int $tenantId, string $workflowKey): string
    {
        return sprintf('google_calendar_workflow_calendars:%d:%s', $tenantId, $workflowKey);
    }

    protected function stateCache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string) config('services.google_calendar.oauth_state_cache_store', config('cache.default', 'file')));
    }

    protected function calendarCache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string) config('cache.default', 'file'));
    }

    protected function normalizeWorkflowKey(string $workflowKey): string
    {
        $normalized = $this->workflowSettingsService->normalizeBaseWorkflowKey($workflowKey);

        return $normalized !== '' ? $normalized : self::WORKFLOW_KEY;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
