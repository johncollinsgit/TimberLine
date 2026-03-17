<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyEmbeddedAppContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedCustomersController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): Response {
        $context = $contextService->resolvePageContext($request);

        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);

        return $this->embeddedResponse(
            response()->view('shopify.customers', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status),
                'subheadline' => $this->subheadlineForStatus($status),
                'appNavigation' => $this->embeddedAppNavigation('customers'),
                'pageActions' => [],
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
    }

    protected function embeddedResponse(Response $response, int $status = 200): Response
    {
        $response->setStatusCode($status);
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;"
        );
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    protected function headlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => 'Customer management',
        };
    }

    protected function subheadlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside your Shopify admin so it can verify the store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
            default => 'A view of Candle Cash balances, ledger history, and referral/birthday status is coming soon.',
        };
    }
}
