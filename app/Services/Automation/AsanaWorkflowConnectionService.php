<?php

namespace App\Services\Automation;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AsanaWorkflowConnectionService
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
        $oauthReady = $credentials['asana_oauth_client_id'] !== null
            && $credentials['asana_oauth_client_secret'] !== null
            && $redirectUri !== null;
        $oauthConnected = $oauthReady && $credentials['asana_oauth_refresh_token'] !== null;
        $tokenReady = $oauthConnected
            || $credentials['asana_personal_access_token'] !== null
            || $credentials['asana_access_token'] !== null;
        $cachedProjects = $this->projectCache()->get($this->projectCacheKey($tenantId, $workflowKey));
        $hasCachedProjects = is_array($cachedProjects);

        $projects = [];
        $error = null;

        if ($oauthConnected || $hasCachedProjects) {
            try {
                $projects = $this->projectOptions($tenantId, $workflowKey);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $selectedProjectGid = $this->nullableString(data_get(
            $this->workflowSettingsService->forTenant($tenantId, $workflowKey),
            'trigger.project_gid'
        ));

        $selectedProject = collect($projects)->first(
            fn (array $project): bool => trim((string) ($project['gid'] ?? '')) === $selectedProjectGid
        );

        return [
            'oauth_ready' => $oauthReady,
            'oauth_connected' => $oauthConnected,
            'token_ready' => $tokenReady,
            'redirect_uri' => $redirectUri,
            'projects' => $projects,
            'selected_project_gid' => $selectedProjectGid,
            'selected_project_name' => is_array($selectedProject) ? ($selectedProject['name'] ?? null) : null,
            'selected_workspace_name' => is_array($selectedProject) ? ($selectedProject['workspace_name'] ?? null) : null,
            'error' => $error,
            'auth_mode' => $oauthConnected
                ? 'oauth'
                : (($credentials['asana_personal_access_token'] !== null || $credentials['asana_access_token'] !== null)
                    ? 'personal_access_token'
                    : 'missing'),
            'credential_sources' => [
                'personal_access_token' => $credentials['sources']['asana_personal_access_token'] ?? 'missing',
                'client_id' => $credentials['sources']['asana_oauth_client_id'] ?? 'missing',
                'client_secret' => $credentials['sources']['asana_oauth_client_secret'] ?? 'missing',
                'refresh_token' => $credentials['sources']['asana_oauth_refresh_token'] ?? 'missing',
            ],
        ];
    }

    public function buildConnectUrl(int $tenantId, User $user, string $workflowKey = self::WORKFLOW_KEY): string
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $clientId = $this->nullableString($credentials['asana_oauth_client_id'] ?? null);
        $clientSecret = $this->nullableString($credentials['asana_oauth_client_secret'] ?? null);
        $redirectUri = $this->redirectUri();

        if ($clientId === null || $clientSecret === null || $redirectUri === null) {
            throw new AutomationWorkflowException('Asana OAuth needs a client ID, client secret, and callback URL before you can connect it.');
        }

        $state = Str::random(48);
        $codeVerifier = Str::random(96);
        $this->stateCache()->put($this->stateCacheKey($state), [
            'user_id' => (int) $user->id,
            'tenant_id' => $tenantId,
            'workflow_key' => $workflowKey,
            'code_verifier' => $codeVerifier,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge_method' => 'S256',
            'code_challenge' => $this->codeChallenge($codeVerifier),
        ];

        $scopes = $this->scopes();
        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }

        return 'https://app.asana.com/-/oauth_authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string,mixed>
     */
    public function connectFromCallback(string $code, string $state): array
    {
        $cached = $this->stateCache()->pull($this->stateCacheKey($state));
        if (
            ! is_array($cached)
            || (int) ($cached['tenant_id'] ?? 0) <= 0
            || trim((string) ($cached['workflow_key'] ?? '')) === ''
            || trim((string) ($cached['code_verifier'] ?? '')) === ''
        ) {
            throw new AutomationWorkflowException('Asana connection state expired. Start the connection again.');
        }

        $tenantId = (int) $cached['tenant_id'];
        $workflowKey = $this->normalizeWorkflowKey((string) $cached['workflow_key']);

        $user = User::query()->find((int) ($cached['user_id'] ?? 0));
        if (! $user || ! in_array((string) $user->role, ['admin', 'marketing_manager'], true)) {
            throw new AutomationWorkflowException('Only admins or marketing managers can connect Asana.');
        }

        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $token = $this->exchangeCode(
            code: trim($code),
            codeVerifier: trim((string) $cached['code_verifier']),
            clientId: $credentials['asana_oauth_client_id'] ?? null,
            clientSecret: $credentials['asana_oauth_client_secret'] ?? null,
        );

        $refreshToken = $this->nullableString((string) ($token['refresh_token'] ?? ''))
            ?? $this->nullableString($credentials['asana_oauth_refresh_token'] ?? null);

        if ($refreshToken === null) {
            throw new AutomationWorkflowException('Asana did not return a refresh token. Reconnect and grant consent again.');
        }

        $tokenUser = is_array($token['user'] ?? null) ? $token['user'] : [];

        $this->workflowSettingsService->mergeStoredValue($tenantId, $workflowKey, [
            'credentials' => [
                'asana_oauth_refresh_token_encrypted' => Crypt::encryptString($refreshToken),
            ],
            'asana_oauth' => [
                'connected_at' => now()->toIso8601String(),
                'connected_by_user_id' => $user->id,
                'granted_scopes' => $token['granted_scopes'] ?? $this->scopes(),
                'token_type' => $token['token_type'] ?? 'bearer',
                'asana_user_gid' => $this->nullableString($tokenUser['gid'] ?? null),
                'asana_user_name' => $this->nullableString($tokenUser['name'] ?? null),
                'asana_user_email' => $this->nullableString($tokenUser['email'] ?? null),
            ],
        ]);

        $projects = $this->projectOptions($tenantId, $workflowKey, true);
        $stored = $this->workflowSettingsService->storedValueForTenant($tenantId, $workflowKey);
        $currentProjectGid = $this->nullableString(data_get($stored, 'trigger.project_gid'));
        $autoSelected = false;

        if ($currentProjectGid === null && count($projects) === 1) {
            $this->workflowSettingsService->mergeStoredValue($tenantId, $workflowKey, [
                'trigger' => [
                    'project_gid' => (string) ($projects[0]['gid'] ?? ''),
                ],
            ]);
            $autoSelected = true;
        }

        return [
            'tenant_id' => $tenantId,
            'workflow_key' => $workflowKey,
            'projects' => $projects,
            'auto_selected' => $autoSelected,
        ];
    }

    public function disconnect(int $tenantId, string $workflowKey = self::WORKFLOW_KEY): void
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $refreshToken = $this->nullableString($credentials['asana_oauth_refresh_token'] ?? null);
        $clientId = $this->nullableString($credentials['asana_oauth_client_id'] ?? null);
        $clientSecret = $this->nullableString($credentials['asana_oauth_client_secret'] ?? null);

        if ($refreshToken !== null && $clientId !== null && $clientSecret !== null) {
            try {
                Http::asForm()
                    ->acceptJson()
                    ->timeout(20)
                    ->retry(2, 250, throw: false)
                    ->post('https://app.asana.com/-/oauth_revoke', [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'token' => $refreshToken,
                    ]);
            } catch (\Throwable) {
                // Clearing the local token is still the priority.
            }
        }

        $this->workflowSettingsService->mergeStoredValue($tenantId, $workflowKey, [
            'credentials' => [
                'asana_oauth_refresh_token_encrypted' => null,
            ],
            'asana_oauth' => [
                'connected_at' => null,
                'connected_by_user_id' => null,
                'granted_scopes' => [],
                'token_type' => null,
                'asana_user_gid' => null,
                'asana_user_name' => null,
                'asana_user_email' => null,
            ],
        ]);

        $this->projectCache()->forget($this->projectCacheKey($tenantId, $workflowKey));
    }

    /**
     * @return array<int,array{gid:string,name:string,workspace_gid:string,workspace_name:string,team_name:?string,permalink_url:?string,icon:?string,color:?string}>
     */
    public function projectOptions(int $tenantId, string $workflowKey = self::WORKFLOW_KEY, bool $forceRefresh = false): array
    {
        $workflowKey = $this->normalizeWorkflowKey($workflowKey);
        $cacheKey = $this->projectCacheKey($tenantId, $workflowKey);

        if (! $forceRefresh) {
            $cached = $this->projectCache()->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $credentials = $this->workflowSettingsService->effectiveCredentials($tenantId, $workflowKey);
        $accessToken = $this->accessTokenFromCredentials($credentials);
        $workspaces = $this->fetchWorkspaces($accessToken);

        $projects = [];
        foreach ($workspaces as $workspace) {
            $workspaceGid = trim((string) ($workspace['gid'] ?? ''));
            $workspaceName = trim((string) ($workspace['name'] ?? ''));
            if ($workspaceGid === '' || $workspaceName === '') {
                continue;
            }

            foreach ($this->fetchProjectsForWorkspace($accessToken, $workspaceGid, $workspaceName) as $project) {
                $projects[] = $project;
            }
        }

        usort($projects, static function (array $left, array $right): int {
            $leftOrder = [
                strtolower((string) ($left['workspace_name'] ?? '')),
                strtolower((string) ($left['name'] ?? '')),
            ];
            $rightOrder = [
                strtolower((string) ($right['workspace_name'] ?? '')),
                strtolower((string) ($right['name'] ?? '')),
            ];

            return $leftOrder <=> $rightOrder;
        });

        $this->projectCache()->put($cacheKey, $projects, now()->addMinutes(15));

        return $projects;
    }

    /**
     * @return array<int,string>
     */
    protected function scopes(): array
    {
        $configured = preg_split('/[\s,]+/', (string) config('services.asana.oauth_scopes', 'projects:read,tasks:read,workspaces:read')) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            $configured
        ))));
    }

    protected function redirectUri(): ?string
    {
        return $this->nullableString(config('services.asana.redirect_uri'));
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function accessTokenFromCredentials(array $credentials): string
    {
        $configuredAccessToken = $this->nullableString($credentials['asana_access_token'] ?? null);
        if ($configuredAccessToken !== null) {
            return $configuredAccessToken;
        }

        $refreshToken = $this->nullableString($credentials['asana_oauth_refresh_token'] ?? null);
        $clientId = $this->nullableString($credentials['asana_oauth_client_id'] ?? null);
        $clientSecret = $this->nullableString($credentials['asana_oauth_client_secret'] ?? null);

        if ($refreshToken !== null && $clientId !== null && $clientSecret !== null) {
            return $this->refreshAccessToken($clientId, $clientSecret, $refreshToken);
        }

        $personalAccessToken = $this->nullableString($credentials['asana_personal_access_token'] ?? null);
        if ($personalAccessToken !== null) {
            return $personalAccessToken;
        }

        throw new AutomationWorkflowException('Asana needs either OAuth or a personal access token before projects can be loaded.');
    }

    /**
     * @return array<string,mixed>
     */
    protected function exchangeCode(string $code, string $codeVerifier, ?string $clientId, ?string $clientSecret): array
    {
        $clientId = $this->nullableString($clientId);
        $clientSecret = $this->nullableString($clientSecret);
        $redirectUri = $this->redirectUri();

        if ($clientId === null || $clientSecret === null || $redirectUri === null) {
            throw new AutomationWorkflowException('Asana OAuth is not configured yet.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://app.asana.com/-/oauth_token', [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
                'code_verifier' => $codeVerifier,
            ]);

        return $this->decodeTokenResponse($response->status(), $response->json());
    }

    protected function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://app.asana.com/-/oauth_token', [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);

        $token = $this->decodeTokenResponse($response->status(), $response->json());
        $accessToken = $this->nullableString($token['access_token'] ?? null);

        if ($accessToken === null) {
            throw new AutomationWorkflowException('Asana OAuth token response did not include an access token.');
        }

        return $accessToken;
    }

    /**
     * @return array<int,array{gid:string,name:string,is_organization:bool}>
     */
    protected function fetchWorkspaces(string $accessToken): array
    {
        $items = [];
        $offset = null;

        do {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->get($this->asanaApiBase().'/workspaces', array_filter([
                    'limit' => 100,
                    'offset' => $offset,
                    'opt_fields' => 'gid,name,is_organization',
                ]));

            $json = $this->decodeApiResponse($response->status(), $response->json(), 'Asana workspaces request failed.');

            foreach ((array) ($json['data'] ?? []) as $workspace) {
                if (! is_array($workspace)) {
                    continue;
                }

                $gid = trim((string) ($workspace['gid'] ?? ''));
                $name = trim((string) ($workspace['name'] ?? ''));
                if ($gid === '' || $name === '') {
                    continue;
                }

                $items[] = [
                    'gid' => $gid,
                    'name' => $name,
                    'is_organization' => (bool) ($workspace['is_organization'] ?? false),
                ];
            }

            $offset = $this->nullableString(data_get($json, 'next_page.offset'));
        } while ($offset !== null);

        return $items;
    }

    /**
     * @return array<int,array{gid:string,name:string,workspace_gid:string,workspace_name:string,team_name:?string,permalink_url:?string,icon:?string,color:?string}>
     */
    protected function fetchProjectsForWorkspace(string $accessToken, string $workspaceGid, string $workspaceName): array
    {
        $items = [];
        $offset = null;

        do {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->get($this->asanaApiBase().'/workspaces/'.rawurlencode($workspaceGid).'/projects', array_filter([
                    'archived' => 'false',
                    'limit' => 100,
                    'offset' => $offset,
                    'opt_fields' => 'gid,name,permalink_url,icon,color,team.name,workspace.name,archived',
                ]));

            $json = $this->decodeApiResponse($response->status(), $response->json(), 'Asana projects request failed.');

            foreach ((array) ($json['data'] ?? []) as $project) {
                if (! is_array($project) || (bool) ($project['archived'] ?? false)) {
                    continue;
                }

                $gid = trim((string) ($project['gid'] ?? ''));
                $name = trim((string) ($project['name'] ?? ''));
                if ($gid === '' || $name === '') {
                    continue;
                }

                $items[] = [
                    'gid' => $gid,
                    'name' => $name,
                    'workspace_gid' => $workspaceGid,
                    'workspace_name' => trim((string) data_get($project, 'workspace.name', $workspaceName)) ?: $workspaceName,
                    'team_name' => $this->nullableString(data_get($project, 'team.name')),
                    'permalink_url' => $this->nullableString($project['permalink_url'] ?? null),
                    'icon' => $this->nullableString($project['icon'] ?? null),
                    'color' => $this->nullableString($project['color'] ?? null),
                ];
            }

            $offset = $this->nullableString(data_get($json, 'next_page.offset'));
        } while ($offset !== null);

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
            $scopeString = $this->nullableString($json['scope'] ?? null);

            return [
                'access_token' => $this->nullableString($json['access_token'] ?? null),
                'refresh_token' => $this->nullableString($json['refresh_token'] ?? null),
                'token_type' => $this->nullableString($json['token_type'] ?? null) ?? 'bearer',
                'granted_scopes' => $scopeString !== null
                    ? array_values(array_filter(preg_split('/\s+/', $scopeString) ?: []))
                    : $this->scopes(),
                'user' => is_array($json['data'] ?? null) ? $json['data'] : [],
            ];
        }

        $message = trim((string) Arr::get($json, 'error_description', Arr::get($json, 'errors.0.message', Arr::get($json, 'error', 'Asana OAuth request failed.'))));

        throw new AutomationWorkflowException($message !== '' ? $message : 'Asana OAuth request failed.');
    }

    /**
     * @param  mixed  $payload
     * @return array<string,mixed>
     */
    protected function decodeApiResponse(int $status, mixed $payload, string $defaultMessage): array
    {
        $json = is_array($payload) ? $payload : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        $message = trim((string) Arr::get($json, 'errors.0.message', Arr::get($json, 'error.message', Arr::get($json, 'error', $defaultMessage))));

        throw new AutomationWorkflowException($message !== '' ? $message : $defaultMessage);
    }

    protected function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    protected function stateCacheKey(string $state): string
    {
        return 'asana_workflow_oauth_state:'.$state;
    }

    protected function projectCacheKey(int $tenantId, string $workflowKey): string
    {
        return sprintf('asana_workflow_projects:%d:%s', $tenantId, $workflowKey);
    }

    protected function stateCache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string) config('services.asana.oauth_state_cache_store', config('cache.default', 'file')));
    }

    protected function projectCache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string) config('cache.default', 'file'));
    }

    protected function normalizeWorkflowKey(string $workflowKey): string
    {
        $normalized = $this->workflowSettingsService->normalizeBaseWorkflowKey($workflowKey);

        return $normalized !== '' ? $normalized : self::WORKFLOW_KEY;
    }

    protected function asanaApiBase(): string
    {
        return rtrim((string) config('services.asana.api_base', 'https://app.asana.com/api/1.0'), '/');
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
