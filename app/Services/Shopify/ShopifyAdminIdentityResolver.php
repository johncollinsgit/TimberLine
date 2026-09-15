<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ShopifyAdminIdentityResolver
{
    public function emailForVerifiedSession(array $context, string $token): ?string
    {
        $shop = (string) ($context['shop_domain'] ?? '');
        $clientId = (string) ($context['shopify_app_client_id'] ?? '');
        $userId = (string) ($context['shopify_admin_user_id'] ?? '');
        if (! ($context['ok'] ?? false) || ($context['auth_source'] ?? '') !== 'session_token'
            || $token === '' || $userId === '' || $clientId === ''
            || ! preg_match('/^[a-z0-9-]+\.myshopify\.com$/', $shop)) {
            return null;
        }
        $credentials = app(ShopifyEmbeddedAppCredentials::class)->credentialsForStore($context['store'] ?? []);
        $credential = collect($credentials)->firstWhere('client_id', $clientId);
        if (! $credential) {
            return null;
        }

        return Cache::remember('shopify-reviewer-email:'.hash('sha256', $shop.'|'.$clientId.'|'.$userId), 300, function () use ($shop, $userId, $credential, $token) {
            try {
                // Shopify ID tokens normally contain sub, not email. Resolve the
                // verified staff email from Shopify; never infer it from shop.email.
                $response = Http::asForm()->acceptJson()->timeout(10)->post('https://'.$shop.'/admin/oauth/access_token', [
                    'client_id' => $credential['client_id'],
                    'client_secret' => $credential['secret'],
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $token,
                    'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:online-access-token',
                ]);
                $user = $response->json('associated_user');
                if (! $response->successful() || ! is_array($user)
                    || (string) ($user['id'] ?? '') !== $userId || ($user['email_verified'] ?? false) !== true) {
                    return null;
                }
                $email = strtolower(trim((string) ($user['email'] ?? '')));

                // Discard the online access token. Only the verified email is cached.
                return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            } catch (\Throwable) {
                // Provider response bodies can contain credentials; do not log them.
                return null;
            }
        });
    }
}
