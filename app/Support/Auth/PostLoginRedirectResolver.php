<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PostLoginRedirectResolver
{
    public function __construct(
        protected AuthTenantIntentStore $intentStore,
    ) {
    }

    public function resolve(Request $request, User $user, string $authMethod = 'password'): string
    {
        $membershipMap = $this->membershipMap($user);
        $intent = $this->intentStore->pull($request);

        $tenantIntentExists = $intent !== null;
        $tenantIntentId = $intent['tenant_id'] ?? null;
        $tenantMembershipPassed = is_int($tenantIntentId)
            ? in_array($tenantIntentId, $membershipMap['ids'], true)
            : false;

        if ($tenantMembershipPassed && $request->hasSession()) {
            $request->session()->put('tenant_id', $tenantIntentId);
        }

        $intendedDecision = $this->pullIntendedDecision($request, $membershipMap);

        $target = HomeRedirect::pathFor($user);
        $strategy = 'role_fallback';

        if ($intendedDecision['accepted'] && is_string($intendedDecision['path'])) {
            $target = $intendedDecision['path'];
            $strategy = 'intended_url';
        } elseif ($tenantIntentExists && $tenantMembershipPassed) {
            $strategy = 'tenant_intent';
        } elseif ($target === '') {
            $target = route('home', absolute: false);
            $strategy = 'safe_fallback';
        }

        Log::info('auth.post_login.redirect_decision', [
            'category' => 'auth.redirect',
            'event' => 'post_login_redirect_decision',
            'auth_method' => $authMethod,
            'tenant_intent_exists' => $tenantIntentExists,
            'tenant_intent_tenant_id' => $tenantIntentId,
            'tenant_membership_passed' => $tenantMembershipPassed,
            'intended_present' => $intendedDecision['present'],
            'intended_accepted' => $intendedDecision['accepted'],
            'strategy' => $strategy,
            'target' => $target,
        ]);

        return $target;
    }

    /**
     * @return array{present:bool,accepted:bool,path:?string}
     */
    protected function pullIntendedDecision(Request $request, array $membershipMap): array
    {
        if (! $request->hasSession()) {
            return ['present' => false, 'accepted' => false, 'path' => null];
        }

        $rawIntended = $request->session()->pull('url.intended');
        if (! is_string($rawIntended)) {
            return ['present' => false, 'accepted' => false, 'path' => null];
        }

        $rawIntended = trim($rawIntended);
        if ($rawIntended === '') {
            return ['present' => false, 'accepted' => false, 'path' => null];
        }

        $parsed = parse_url($rawIntended);
        if (! is_array($parsed)) {
            return ['present' => true, 'accepted' => false, 'path' => null];
        }

        $host = isset($parsed['host']) ? $this->normalizeHost((string) $parsed['host']) : null;
        if ($host !== null && ! in_array($host, $this->allowedHosts($request), true)) {
            return ['present' => true, 'accepted' => false, 'path' => null];
        }

        $query = isset($parsed['query']) ? (string) $parsed['query'] : '';
        if (! $this->tenantQueryIsSafe($query, $membershipMap)) {
            return ['present' => true, 'accepted' => false, 'path' => null];
        }

        $path = isset($parsed['path']) ? (string) $parsed['path'] : '/';
        $path = trim($path) === '' ? '/' : $path;

        if (str_starts_with($path, '//')) {
            return ['present' => true, 'accepted' => false, 'path' => null];
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if ($query !== '') {
            $path .= '?'.$query;
        }

        return ['present' => true, 'accepted' => true, 'path' => $path];
    }

    /**
     * @return array{ids:array<int,int>,slugs:array<int,string>}
     */
    protected function membershipMap(User $user): array
    {
        $memberships = $user->tenants()
            ->select('tenants.id', 'tenants.slug')
            ->get();

        $ids = [];
        $slugs = [];

        foreach ($memberships as $tenant) {
            if (is_numeric($tenant->id) && (int) $tenant->id > 0) {
                $ids[] = (int) $tenant->id;
            }

            $normalizedSlug = $this->normalizeToken((string) ($tenant->slug ?? ''));
            if ($normalizedSlug !== null) {
                $slugs[] = $normalizedSlug;
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'slugs' => array_values(array_unique($slugs)),
        ];
    }

    /**
     * @param  array{ids:array<int,int>,slugs:array<int,string>}  $membershipMap
     */
    protected function tenantQueryIsSafe(string $query, array $membershipMap): bool
    {
        if ($query === '') {
            return true;
        }

        parse_str($query, $params);

        foreach (['tenant_id', 'tenant'] as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];
            if (is_array($value)) {
                return false;
            }

            $token = trim((string) $value);
            if ($token === '') {
                continue;
            }

            if (is_numeric($token)) {
                $tenantId = (int) $token;
                if ($tenantId <= 0 || ! in_array($tenantId, $membershipMap['ids'], true)) {
                    return false;
                }

                continue;
            }

            $slug = $this->normalizeToken($token);
            if ($slug === null || ! in_array($slug, $membershipMap['slugs'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int,string>
     */
    protected function allowedHosts(Request $request): array
    {
        $hosts = [];

        $requestHost = $this->normalizeHost((string) $request->getHost());
        if ($requestHost !== null) {
            $hosts[] = $requestHost;
        }

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        $appHost = is_string($appHost) ? $this->normalizeHost($appHost) : null;
        if ($appHost !== null) {
            $hosts[] = $appHost;
        }

        $flagshipHosts = config('tenancy.auth.flagship_hosts', []);
        if (is_array($flagshipHosts)) {
            foreach ($flagshipHosts as $host) {
                $normalized = $this->normalizeHost((string) $host);
                if ($normalized !== null) {
                    $hosts[] = $normalized;
                }
            }
        }

        $hostMap = config('tenancy.auth.host_map', []);
        if (is_array($hostMap)) {
            foreach (array_keys($hostMap) as $host) {
                $normalized = $this->normalizeHost((string) $host);
                if ($normalized !== null) {
                    $hosts[] = $normalized;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    protected function normalizeHost(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));

        return $host !== '' ? $host : null;
    }

    protected function normalizeToken(?string $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token !== '' ? $token : null;
    }
}
