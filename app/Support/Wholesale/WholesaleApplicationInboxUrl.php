<?php

namespace App\Support\Wholesale;

use App\Models\CustomerAccessRequest;
use App\Support\Tenancy\TenantHostBuilder;

class WholesaleApplicationInboxUrl
{
    protected const WHOLESALE_STORE_KEY = 'wholesale';

    public function __construct(protected TenantHostBuilder $hostBuilder) {}

    public function inboxUrl(?string $tenantSlug = null): string
    {
        $path = route('shopify.app.wholesale.applications', [
            'store_key' => self::WHOLESALE_STORE_KEY,
        ], false);

        return $this->absoluteUrlForPath($path, $tenantSlug)
            ?? route('shopify.app.wholesale.applications', [
                'store_key' => self::WHOLESALE_STORE_KEY,
            ]);
    }

    public function detailUrl(CustomerAccessRequest|int $accessRequest, ?string $tenantSlug = null): string
    {
        $requestId = $accessRequest instanceof CustomerAccessRequest
            ? (int) $accessRequest->getKey()
            : (int) $accessRequest;

        $path = route('shopify.app.wholesale.applications.show', [
            'accessRequest' => $requestId,
            'store_key' => self::WHOLESALE_STORE_KEY,
        ], false);
        $resolvedSlug = $tenantSlug;

        if ($resolvedSlug === null && $accessRequest instanceof CustomerAccessRequest) {
            $resolvedSlug = filled($accessRequest->requested_tenant_slug)
                ? (string) $accessRequest->requested_tenant_slug
                : null;
        }

        return $this->absoluteUrlForPath($path, $resolvedSlug)
            ?? route('shopify.app.wholesale.applications.show', [
                'accessRequest' => $requestId,
                'store_key' => self::WHOLESALE_STORE_KEY,
            ]);
    }

    protected function absoluteUrlForPath(string $path, ?string $tenantSlug = null): ?string
    {
        return $this->hostBuilder->canonicalLandlordUrlForPath($path);
    }
}
