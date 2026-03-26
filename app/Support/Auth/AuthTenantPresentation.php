<?php

namespace App\Support\Auth;

class AuthTenantPresentation
{
    /**
     * @return array{
     *   variant:string,
     *   app_name:string,
     *   tenant_label:string,
     *   portal_name:string,
     *   hero_title:string,
     *   hero_subtitle:string,
     *   hero_tagline:string,
     *   login_eyebrow:string,
     *   login_title:string,
     *   login_subtitle:string
     * }
     */
    public static function fromContext(AuthTenantContext $context): array
    {
        $tenantName = trim((string) ($context->tenant?->name ?? ''));
        $resolvedTenantName = $tenantName !== ''
            ? $tenantName
            : (string) config('tenancy.auth.fallback_tenant_label', 'Modern Forestry');

        $portalName = (string) config('tenancy.auth.portal_name', 'Backstage');

        if ($context->classification === AuthTenantContext::GENERIC) {
            return [
                'variant' => AuthTenantContext::GENERIC,
                'app_name' => $resolvedTenantName.' '.$portalName,
                'tenant_label' => $resolvedTenantName,
                'portal_name' => $portalName,
                'hero_title' => $resolvedTenantName.' operations in one place.',
                'hero_subtitle' => 'Sign in to access your workspace tools and operational dashboards.',
                'hero_tagline' => 'Tenant Console',
                'login_eyebrow' => 'Sign in',
                'login_title' => 'Welcome back',
                'login_subtitle' => 'Sign in to continue to your workspace.',
            ];
        }

        // Preserve the richer flagship/default Modern Forestry experience.
        return [
            'variant' => $context->classification === AuthTenantContext::FLAGSHIP
                ? AuthTenantContext::FLAGSHIP
                : AuthTenantContext::NONE,
            'app_name' => $resolvedTenantName.' '.$portalName,
            'tenant_label' => $resolvedTenantName,
            'portal_name' => $portalName,
            'hero_title' => 'Production, shipping, and wholesale operations in one calm place.',
            'hero_subtitle' => 'Built for real inventory flow. Track orders, line items, and fulfillment without the noise.',
            'hero_tagline' => 'Operations Console',
            'login_eyebrow' => 'Sign in',
            'login_title' => 'Welcome back',
            'login_subtitle' => 'Sign in to continue to your account and pick up where you left off.',
        ];
    }
}
