<?php

namespace App\Services\Shopify;

use Illuminate\Http\Request;

class ShopifyEmbeddedDevelopmentNotesAccess
{
    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   allowed:bool,
     *   reason:string,
     *   shop_domain:?string,
     *   actor_email:?string,
     *   shopify_admin_user_id:?string
     * }
     */
    public function evaluate(Request $request, array $context): array
    {
        $shopDomain = $this->normalizeDomain((string) ($context['shop_domain'] ?? data_get($context, 'store.shop', '')));
        $allowedShops = $this->allowedShopDomains();
        if ($allowedShops !== [] && ($shopDomain === null || ! in_array($shopDomain, $allowedShops, true))) {
            return $this->denied('shop_not_allowlisted', $shopDomain, null, null);
        }

        $actorEmail = $this->resolvedActorEmail($request, $context);
        $shopifyAdminUserId = $this->normalizedNullableString($context['shopify_admin_user_id'] ?? null);

        if ($this->hasAuthenticatedAppUser($request) && ! $this->appUserIsAdmin($request)) {
            return $this->denied('app_user_not_admin', $shopDomain, $actorEmail, $shopifyAdminUserId);
        }

        $allowedEmails = $this->allowedAdminEmails();
        $allowedShopifyAdminUserIds = $this->allowedShopifyAdminUserIds();
        $strictEmailIdentity = (bool) config('shopify_embedded.development_notes.strict_email_identity', true);

        if ($allowedEmails === [] && $allowedShopifyAdminUserIds === []) {
            return $this->allowed($shopDomain, $actorEmail, $shopifyAdminUserId);
        }

        if ($actorEmail !== null && in_array($actorEmail, $allowedEmails, true)) {
            return $this->allowed($shopDomain, $actorEmail, $shopifyAdminUserId);
        }

        if ($shopifyAdminUserId !== null && in_array($shopifyAdminUserId, $allowedShopifyAdminUserIds, true)) {
            return $this->allowed($shopDomain, $actorEmail, $shopifyAdminUserId);
        }

        if (! $strictEmailIdentity && $allowedEmails !== [] && $actorEmail === null && $allowedShopifyAdminUserIds === []) {
            return $this->allowed($shopDomain, null, $shopifyAdminUserId);
        }

        return $this->denied('identity_not_allowlisted', $shopDomain, $actorEmail, $shopifyAdminUserId);
    }

    /**
     * @return array<int,string>
     */
    protected function allowedShopDomains(): array
    {
        $configured = config('shopify_embedded.development_notes.allowed_shop_domains', []);
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalizeDomain((string) $value),
            $configured
        ))));
    }

    /**
     * @return array<int,string>
     */
    protected function allowedAdminEmails(): array
    {
        $configured = config('shopify_embedded.development_notes.allowed_admin_emails', []);
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalizedNullableEmail($value),
            $configured
        ))));
    }

    /**
     * @return array<int,string>
     */
    protected function allowedShopifyAdminUserIds(): array
    {
        $configured = config('shopify_embedded.development_notes.allowed_shopify_admin_user_ids', []);
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalizedNullableString($value),
            $configured
        ))));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function resolvedActorEmail(Request $request, array $context): ?string
    {
        $appUserEmail = $this->normalizedNullableEmail(optional($request->user())->email);
        if ($appUserEmail !== null) {
            return $appUserEmail;
        }

        return $this->normalizedNullableEmail($context['shopify_admin_email'] ?? null);
    }

    protected function hasAuthenticatedAppUser(Request $request): bool
    {
        return $request->user() !== null;
    }

    protected function appUserIsAdmin(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->getAttribute('is_active') === false) {
            return false;
        }

        return method_exists($user, 'isAdmin') ? (bool) $user->isAdmin() : false;
    }

    protected function normalizeDomain(string $value): ?string
    {
        $candidate = strtolower(trim($value));
        if ($candidate === '') {
            return null;
        }

        if (! str_contains($candidate, '://')) {
            $candidate = 'https://' . $candidate;
        }

        $host = (string) parse_url($candidate, PHP_URL_HOST);
        $host = strtolower(trim($host));

        return $host !== '' ? $host : null;
    }

    protected function normalizedNullableEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    protected function normalizedNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function allowed(?string $shopDomain, ?string $actorEmail, ?string $shopifyAdminUserId): array
    {
        return [
            'allowed' => true,
            'reason' => 'ok',
            'shop_domain' => $shopDomain,
            'actor_email' => $actorEmail,
            'shopify_admin_user_id' => $shopifyAdminUserId,
        ];
    }

    protected function denied(string $reason, ?string $shopDomain, ?string $actorEmail, ?string $shopifyAdminUserId): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'shop_domain' => $shopDomain,
            'actor_email' => $actorEmail,
            'shopify_admin_user_id' => $shopifyAdminUserId,
        ];
    }
}
