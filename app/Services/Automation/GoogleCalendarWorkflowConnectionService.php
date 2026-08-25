<?php

namespace App\Services\Automation;

use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendarWorkflowConnectionService
{
    public const WORKFLOW_KEY = 'asana_to_google_calendar';

    public function __construct(
        protected TenantWorkflowAutomationSettingsService $workflowSettingsService,
        protected TenantModuleAccessResolver $moduleAccessResolver,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(int $tenantId, string $workflowKey = self::WORKFLOW_KEY): array
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $credentials = $this->connectionCredentials($tenantId, $workflowKey);
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
        $normalized = IntegrationConnection::query()->forTenantId($tenantId)
            ->where('provider', 'google_calendar')
            ->orderByRaw('status = ? desc', [IntegrationConnection::STATUS_CONNECTED])
            ->latest('connected_at')
            ->first();

        return [
            'oauth_ready' => $oauthReady,
            'connected' => $connected,
            'redirect_uri' => $redirectUri,
            'calendars' => $calendars,
            'selected_calendar_id' => $selectedCalendarId,
            'selected_calendar_summary' => is_array($selectedCalendar) ? ($selectedCalendar['summary'] ?? null) : null,
            'error' => $error,
            'account_label' => $normalized?->external_account_label,
            'last_checked_at' => $normalized?->last_synced_at?->toIso8601String() ?? $normalized?->connected_at?->toIso8601String(),
            'connection_status' => $normalized?->status ?? ($connected ? IntegrationConnection::STATUS_CONNECTED : IntegrationConnection::STATUS_DISCONNECTED),
            'credential_sources' => [
                'client_id' => $credentials['sources']['google_calendar_client_id'] ?? 'missing',
                'client_secret' => $credentials['sources']['google_calendar_client_secret'] ?? 'missing',
                'refresh_token' => $credentials['sources']['google_calendar_refresh_token'] ?? 'missing',
            ],
        ];
    }

    public function buildConnectUrl(int $tenantId, User $user, string $workflowKey = self::WORKFLOW_KEY, string $returnPath = '/workflows/connections'): string
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
            'oauth_client_id_encrypted' => Crypt::encryptString($clientId),
            'oauth_client_secret_encrypted' => Crypt::encryptString($clientSecret),
            'oauth_client_source' => (string) data_get(
                $credentials,
                'sources.google_calendar_client_id',
                'missing',
            ),
            'return_path' => $this->safeReturnPath($returnPath),
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
    public function connectFromCallback(string $code, string $state, ?User $actor = null): array
    {
        $cached = $this->stateCache()->pull($this->stateCacheKey($state));
        if (! is_array($cached) || (int) ($cached['tenant_id'] ?? 0) <= 0 || trim((string) ($cached['workflow_key'] ?? '')) === '') {
            throw new AutomationWorkflowException('Google Calendar connection state expired. Start the connection again.');
        }

        $tenantId = (int) $cached['tenant_id'];
        $workflowKey = $this->normalizeWorkflowKey((string) $cached['workflow_key']);

        $user = User::query()->find((int) ($cached['user_id'] ?? 0));
        if (! $user || ! in_array((string) $user->role, ['admin', 'manager', 'marketing_manager'], true)) {
            throw new AutomationWorkflowException('Only admins or marketing managers can connect Google Calendar.');
        }
        if (! $user->tenants()->whereKey($tenantId)->exists() || ! $this->moduleAccessResolver->canAccess($tenantId, 'workflow_automations')) {
            throw new AutomationWorkflowException('This Google Calendar connection is no longer authorized for the workspace.');
        }
        if ($actor !== null && (int) $actor->id !== (int) $user->id) {
            throw new AutomationWorkflowException('Google Calendar connection state belongs to a different user.');
        }

        [$clientId, $clientSecret] = $this->cachedOAuthClientCredentials($cached);
        $token = $this->exchangeCode(
            code: trim($code),
            clientId: $clientId,
            clientSecret: $clientSecret,
        );

        $refreshToken = $this->nullableString((string) ($token['refresh_token'] ?? ''))
            ?? $this->existingRefreshTokenForClient($tenantId, $clientId, $clientSecret);

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

        $this->persistSharedConnection(
            tenantId: $tenantId,
            values: [
                'external_account_label' => 'Google Calendar account',
                'refresh_token' => $refreshToken,
                'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
                'oauth_client_id' => $clientId,
                'oauth_client_secret' => $clientSecret,
                'scopes' => $token['granted_scopes'] ?? $this->scopes(),
                'connected_by_user_id' => $user->id,
            ],
            oauthClientSource: (string) ($cached['oauth_client_source'] ?? 'missing'),
        );

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
            'return_path' => $this->safeReturnPath((string) ($cached['return_path'] ?? '/workflows/connections')),
        ];
    }

    /** @param array<string,mixed> $values */
    protected function persistSharedConnection(
        int $tenantId,
        array $values,
        string $oauthClientSource,
    ): IntegrationConnection {
        $externalAccountId = 'google-calendar-workflow-account';
        $query = IntegrationConnection::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'google_calendar');
        $connection = (clone $query)->where('external_account_id', $externalAccountId)->first()
            ?? (clone $query)->where('external_account_id', '')->oldest('id')->first()
            ?? new IntegrationConnection(['tenant_id' => $tenantId, 'provider' => 'google_calendar']);

        $connection->fill([
            ...$values,
            'external_account_id' => $externalAccountId,
            'status' => IntegrationConnection::STATUS_CONNECTED,
            'metadata' => [
                ...(array) $connection->metadata,
                'credential_source' => $oauthClientSource === 'global'
                    ? 'shared_oauth'
                    : 'legacy_tenant',
                'oauth_client_source' => $oauthClientSource,
            ],
            'connected_at' => now(),
            'last_synced_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        (clone $query)->whereKeyNot($connection->id)->update([
            'status' => IntegrationConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'oauth_client_id' => null,
            'oauth_client_secret' => null,
            'last_synced_at' => now(),
        ]);

        return $connection;
    }

    protected function safeReturnPath(string $path): string
    {
        $path = trim($path);

        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            ? $path
            : '/workflows/connections';
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
        IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenantId)->where('provider', 'google_calendar')->update([
            'status' => IntegrationConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'oauth_client_id' => null,
            'oauth_client_secret' => null,
            'last_synced_at' => now(),
        ]);
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

        $credentials = $this->connectionCredentials($tenantId, $workflowKey);
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
     * Read a bounded upcoming-event window for an authenticated tenant calendar.
     * This intentionally has no event mutation path.
     *
     * @return array<int,array{id:string,summary:string,start:?string,end:?string,location:?string,url:?string}>
     */
    public function upcomingEvents(
        int $tenantId,
        ?string $calendarId = null,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
        string $workflowKey = self::WORKFLOW_KEY,
    ): array {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $calendarId = $this->nullableString($calendarId);
        if ($calendarId === null) {
            $calendarId = collect($this->calendarOptions($tenantId, $workflowKey))
                ->sortByDesc(fn (array $calendar): bool => (bool) ($calendar['primary'] ?? false))
                ->value('id');
            $calendarId = $this->nullableString($calendarId);
        }
        if ($calendarId === null) {
            return [];
        }

        $credentials = $this->connectionCredentials($tenantId, $workflowKey);
        $accessToken = $this->refreshAccessToken(
            clientId: $credentials['google_calendar_client_id'] ?? null,
            clientSecret: $credentials['google_calendar_client_secret'] ?? null,
            refreshToken: $credentials['google_calendar_refresh_token'] ?? null,
        );
        $start ??= now()->startOfDay();
        $end ??= now()->addDays(45)->endOfDay();
        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)->retry(2, 250, throw: false)
            ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', [
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'timeMin' => $start->format(DATE_RFC3339),
                'timeMax' => $end->format(DATE_RFC3339),
                'maxResults' => 100,
                'fields' => 'items(id,summary,start,end,location,htmlLink,status)',
            ]);
        $payload = is_array($response->json()) ? $response->json() : [];
        if (! $response->successful()) {
            throw new AutomationWorkflowException(trim((string) data_get($payload, 'error.message', 'Google Calendar events could not be read.')));
        }

        return collect((array) ($payload['items'] ?? []))
            ->filter(fn (mixed $event): bool => is_array($event) && (string) ($event['status'] ?? '') !== 'cancelled')
            ->map(fn (array $event): array => [
                'id' => (string) ($event['id'] ?? ''),
                'summary' => trim((string) ($event['summary'] ?? 'Untitled event')) ?: 'Untitled event',
                'start' => $this->nullableString(data_get($event, 'start.dateTime') ?? data_get($event, 'start.date')),
                'end' => $this->nullableString(data_get($event, 'end.dateTime') ?? data_get($event, 'end.date')),
                'location' => $this->nullableString($event['location'] ?? null),
                'url' => $this->nullableString($event['htmlLink'] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * Verify write access without leaving test data behind.
     *
     * @return array{ok:bool,event_id:string,cleanup_ok:bool}
     */
    public function testCalendarWrite(int $tenantId, string $calendarId, string $workflowKey = self::WORKFLOW_KEY): array
    {
        $credentials = $this->connectionCredentials($tenantId, $this->normalizeWorkflowKey($workflowKey));
        $accessToken = $this->refreshAccessToken(
            $credentials['google_calendar_client_id'] ?? null,
            $credentials['google_calendar_client_secret'] ?? null,
            $credentials['google_calendar_refresh_token'] ?? null,
        );

        $start = now()->addDay()->startOfHour();
        $response = Http::acceptJson()->asJson()->withToken($accessToken)->timeout(20)->retry(2, 250, throw: false)
            ->post('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', [
                'summary' => 'Everbranch connection test (safe to remove)',
                'description' => 'Temporary event created while testing an Everbranch workflow connection.',
                'start' => ['dateTime' => $start->toIso8601String()],
                'end' => ['dateTime' => $start->copy()->addMinutes(15)->toIso8601String()],
            ]);

        $payload = is_array($response->json()) ? $response->json() : [];
        if (! $response->successful() || blank($payload['id'] ?? null)) {
            throw new AutomationWorkflowException(trim((string) data_get($payload, 'error.message', 'Google Calendar test event could not be created.')));
        }

        $eventId = (string) $payload['id'];
        $cleanup = Http::acceptJson()->withToken($accessToken)->timeout(20)->retry(2, 250, throw: false)
            ->delete('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));

        return ['ok' => true, 'event_id' => $eventId, 'cleanup_ok' => $cleanup->successful() || $cleanup->status() === 410];
    }

    /**
     * @return array<int,string>
     */
    protected function scopes(): array
    {
        $configured = explode(',', (string) config('services.google_calendar.oauth_scopes', 'https://www.googleapis.com/auth/calendar.events,https://www.googleapis.com/auth/calendar.calendarlist.readonly'));

        return array_values(array_unique(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            $configured
        ))));
    }

    protected function redirectUri(): ?string
    {
        return $this->nullableString(config('services.google_calendar.redirect_uri'));
    }

    /** @return array<string,mixed> */
    protected function connectionCredentials(int $tenantId, string $workflowKey): array
    {
        $connection = IntegrationConnection::query()->forTenantId($tenantId)
            ->where('provider', 'google_calendar')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->latest('connected_at')
            ->latest('id')
            ->first();
        $usesSharedOAuth = data_get($connection?->metadata, 'credential_source') === 'shared_oauth';
        $credentials = $this->workflowSettingsService->effectiveCredentials(
            $tenantId,
            $workflowKey,
            preferLegacyOAuthClients: $connection !== null && ! $usesSharedOAuth,
        );

        if ($connection?->refresh_token) {
            $credentials['google_calendar_refresh_token'] = $connection->refresh_token;
            $credentials['sources']['google_calendar_refresh_token'] = 'connection';
        }
        if (filled($connection?->oauth_client_id) || filled($connection?->oauth_client_secret)) {
            if (blank($connection?->oauth_client_id) || blank($connection?->oauth_client_secret)) {
                throw new AutomationWorkflowException(
                    'Google Calendar connection has an incomplete OAuth client snapshot. Reconnect the account.'
                );
            }
            $credentials['google_calendar_client_id'] = $connection->oauth_client_id;
            $credentials['google_calendar_client_secret'] = $connection->oauth_client_secret;
            $credentials['sources']['google_calendar_client_id'] = 'connection';
            $credentials['sources']['google_calendar_client_secret'] = 'connection';
        }

        return $credentials;
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array{0:string,1:string}
     */
    protected function cachedOAuthClientCredentials(array $state): array
    {
        try {
            $clientId = Crypt::decryptString((string) ($state['oauth_client_id_encrypted'] ?? ''));
            $clientSecret = Crypt::decryptString((string) ($state['oauth_client_secret_encrypted'] ?? ''));
        } catch (\Throwable) {
            throw new AutomationWorkflowException(
                'Google Calendar connection credentials could not be verified. Start the connection again.'
            );
        }

        if (trim($clientId) === '' || trim($clientSecret) === '') {
            throw new AutomationWorkflowException(
                'Google Calendar connection credentials are incomplete. Start the connection again.'
            );
        }

        return [$clientId, $clientSecret];
    }

    protected function existingRefreshTokenForClient(
        int $tenantId,
        string $clientId,
        string $clientSecret,
    ): ?string {
        $connection = IntegrationConnection::query()->forTenantId($tenantId)
            ->where('provider', 'google_calendar')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->latest('connected_at')
            ->latest('id')
            ->first();
        $storedClientId = trim((string) $connection?->oauth_client_id);
        $storedClientSecret = trim((string) $connection?->oauth_client_secret);
        if (
            $storedClientId === ''
            || $storedClientSecret === ''
            || ! hash_equals($storedClientId, $clientId)
            || ! hash_equals($storedClientSecret, $clientSecret)
        ) {
            return null;
        }

        return $this->nullableString($connection?->refresh_token);
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
