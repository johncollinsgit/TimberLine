<?php

namespace App\Http\Controllers;

use App\Services\Reporting\StateSalesTaxReportService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedSalesTaxReportsController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        StateSalesTaxReportService $reports,
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized ? $tenantResolver->resolveTenantIdForStoreContext($store) : null;
        $reportingEnabled = $tenantId !== null && (bool) ($moduleAccessResolver->module($tenantId, 'reporting')['has_access'] ?? false);

        $report = $authorized && $tenantId !== null && $reportingEnabled
            ? $reports->report($tenantId, (string) ($store['key'] ?? ''), $request->query('date_from'), $request->query('date_to'), $request->query('state'))
            : ['summary' => [], 'details' => [], 'totals' => [], 'data_notes' => []];

        return response()->view('shopify.sales-tax-reports', [
            'authorized' => $authorized,
            'status' => $status,
            'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => $context['host'] ?? null,
            'appNavigation' => $this->embeddedAppNavigation('reporting', null, $tenantId),
            'reportingEnabled' => $reportingEnabled,
            'report' => $report,
        ]);
    }
}
