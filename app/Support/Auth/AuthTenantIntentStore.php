<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

class AuthTenantIntentStore
{
    public const SESSION_KEY = 'auth.tenant_intent';
    public const PRESERVE_ON_LOGIN_SESSION_KEY = 'auth.tenant_intent.preserve_on_login';

    public function rememberFromContext(Request $request, AuthTenantContext $context, bool $preserveExisting = false): void
    {
        if (! $request->hasSession()) {
            return;
        }

        if ($preserveExisting && $this->get($request) !== null) {
            return;
        }

        if (! $context->resolved() || ! $context->tenant) {
            $this->clear($request);

            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'tenant_id' => (int) $context->tenant->id,
            'classification' => (string) $context->classification,
            'host' => $context->host !== null ? (string) $context->host : null,
            'captured_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{
     *   tenant_id:int,
     *   classification:string,
     *   host:?string,
     *   captured_at:?string
     * }|null
     */
    public function get(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $this->normalizeIntent($request->session()->get(self::SESSION_KEY));
    }

    /**
     * @return array{
     *   tenant_id:int,
     *   classification:string,
     *   host:?string,
     *   captured_at:?string
     * }|null
     */
    public function pull(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $intent = $this->normalizeIntent($request->session()->pull(self::SESSION_KEY));
        $this->clearPreserveOnLogin($request);

        return $intent;
    }

    public function clear(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(self::SESSION_KEY);
        $this->clearPreserveOnLogin($request);
    }

    public function markPreserveOnLogin(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        if ($this->get($request) === null) {
            return;
        }

        $request->session()->put(self::PRESERVE_ON_LOGIN_SESSION_KEY, true);
    }

    public function shouldPreserveOnLogin(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        return (bool) $request->session()->get(self::PRESERVE_ON_LOGIN_SESSION_KEY, false);
    }

    public function clearPreserveOnLogin(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(self::PRESERVE_ON_LOGIN_SESSION_KEY);
    }

    /**
     * @return array{
     *   tenant_id:int,
     *   classification:string,
     *   host:?string,
     *   captured_at:?string
     * }|null
     */
    protected function normalizeIntent(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $tenantId = isset($value['tenant_id']) && is_numeric($value['tenant_id'])
            ? (int) $value['tenant_id']
            : 0;

        if ($tenantId <= 0) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'classification' => strtolower(trim((string) ($value['classification'] ?? AuthTenantContext::NONE))),
            'host' => isset($value['host']) ? (string) $value['host'] : null,
            'captured_at' => isset($value['captured_at']) ? (string) $value['captured_at'] : null,
        ];
    }
}
