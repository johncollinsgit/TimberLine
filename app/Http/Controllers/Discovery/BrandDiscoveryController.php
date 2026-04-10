<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Discovery\BrandDiscoveryGraphService;
use App\Services\Discovery\DiscoverySitemapService;
use App\Services\Discovery\DiscoveryStructuredDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BrandDiscoveryController extends Controller
{
    public function wellKnown(Request $request, BrandDiscoveryGraphService $graphService): JsonResponse
    {
        $tenantId = $this->resolveTenantIdFromRequest($request);
        $payload = $graphService->buildForTenant($tenantId, [
            'source' => 'well_known',
        ]);

        return response()->json($payload, 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function byTenant(string $tenant, Request $request, BrandDiscoveryGraphService $graphService): JsonResponse
    {
        $tenantId = $this->resolveTenantId($tenant) ?? $this->resolveTenantIdFromRequest($request);
        $payload = $graphService->buildForTenant($tenantId, [
            'source' => 'public_api',
            'tenant_identifier' => $tenant,
        ]);

        return response()->json($payload, 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function structured(
        Request $request,
        DiscoveryStructuredDataService $structuredDataService,
        ?string $tenant = null
    ): JsonResponse {
        $tenantId = $this->resolveTenantId($tenant) ?? $this->resolveTenantIdFromRequest($request);
        $pageKey = $this->nullableString($request->query('page_key'));

        $contracts = $structuredDataService->contractsForTenant($tenantId, [
            'page_key' => $pageKey,
        ]);

        return response()->json([
            'tenant_id' => $tenantId,
            'page_key' => $pageKey,
            'contracts' => $contracts,
            'json_ld_graph' => $structuredDataService->jsonLdGraphForTenant($tenantId, [
                'page_key' => $pageKey,
            ]),
            'generated_at' => now()->toIso8601String(),
        ], 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function sitemap(Request $request, DiscoverySitemapService $sitemapService): Response
    {
        $tenantId = $this->resolveTenantIdFromRequest($request);
        $xml = $sitemapService->buildSitemapXmlForTenant($tenantId);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    protected function resolveTenantIdFromRequest(Request $request): ?int
    {
        $hostTenantId = $request->attributes->get('host_tenant_id');
        if (is_numeric($hostTenantId) && (int) $hostTenantId > 0) {
            return (int) $hostTenantId;
        }

        $slug = $this->nullableString(config('tenancy.auth.flagship_tenant_slug', 'modern-forestry'));
        if ($slug !== null) {
            $tenantId = Tenant::query()->where('slug', $slug)->value('id');
            if (is_numeric($tenantId) && (int) $tenantId > 0) {
                return (int) $tenantId;
            }
        }

        $tenantId = Tenant::query()
            ->where('slug', 'modern-forestry')
            ->orWhere('name', 'Modern Forestry')
            ->value('id');

        return is_numeric($tenantId) && (int) $tenantId > 0 ? (int) $tenantId : null;
    }

    protected function resolveTenantId(?string $identifier): ?int
    {
        $normalized = $this->nullableString($identifier);
        if ($normalized === null) {
            return null;
        }

        if (is_numeric($normalized) && (int) $normalized > 0) {
            return (int) $normalized;
        }

        $tenantId = Tenant::query()
            ->where('slug', $normalized)
            ->orWhere('name', $normalized)
            ->value('id');

        return is_numeric($tenantId) && (int) $tenantId > 0 ? (int) $tenantId : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
