<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyEmbeddedAppContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedAppController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): Response {
        $context = $contextService->resolvePageContext($request);

        if (($context['status'] ?? '') === 'open_from_shopify') {
            return $this->embeddedResponse(
                response()->view('shopify.dashboard', [
                    'authorized' => false,
                    'status' => 'open_from_shopify',
                    'shopifyApiKey' => null,
                    'shopDomain' => null,
                    'host' => null,
                    'storeLabel' => 'Shopify Admin',
                    'headline' => 'Dashboard',
                    'subheadline' => 'Rewards performance snapshot',
                    'appNavigation' => $this->embeddedAppNavigation('dashboard'),
                    'pageActions' => [],
                    'pageSubnav' => [],
                    'dashboardBootstrap' => [
                        'authorized' => false,
                        'status' => 'open_from_shopify',
                        'storeLabel' => 'Shopify Admin',
                        'links' => [],
                    ],
                ])
            );
        }

        if (! ($context['ok'] ?? false)) {
            return $this->embeddedResponse(
                response()->view('shopify.dashboard', [
                    'authorized' => false,
                    'status' => 'invalid_request',
                    'shopifyApiKey' => null,
                    'shopDomain' => $context['shop_domain'] ?? null,
                    'host' => $context['host'] ?? null,
                    'storeLabel' => 'Shopify Admin',
                    'headline' => 'Dashboard',
                    'subheadline' => 'Rewards performance snapshot',
                    'appNavigation' => $this->embeddedAppNavigation('dashboard'),
                    'pageActions' => [],
                    'pageSubnav' => [],
                    'dashboardBootstrap' => [
                        'authorized' => false,
                        'status' => 'invalid_request',
                        'storeLabel' => 'Shopify Admin',
                        'links' => [],
                    ],
                ]),
                401
            );
        }

        /** @var array<string,mixed> $store */
        $store = $context['store'];
        $dashboardLinks = [
            [
                'label' => 'Rewards Admin',
                'href' => route('shopify.embedded.rewards', [], false),
            ],
            [
                'label' => 'Customers',
                'href' => route('shopify.embedded.customers.manage', [], false),
            ],
            [
                'label' => 'Program Settings',
                'href' => route('shopify.embedded.settings', [], false),
            ],
            [
                'label' => 'Birthdays in Backstage',
                'href' => route('birthdays.customers'),
                'external' => true,
            ],
            [
                'label' => 'Marketing Overview',
                'href' => route('marketing.overview'),
                'external' => true,
            ],
        ];

        return $this->embeddedResponse(
            response()->view('shopify.dashboard', [
                'authorized' => true,
                'status' => 'ok',
                'shopifyApiKey' => (string) ($store['client_id'] ?? ''),
                'shopDomain' => (string) ($store['shop'] ?? ''),
                'host' => (string) ($context['host'] ?? ''),
                'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')) . ' Store',
                'headline' => 'Dashboard',
                'subheadline' => 'Rewards performance snapshot',
                'appNavigation' => $this->embeddedAppNavigation('dashboard'),
                'pageActions' => [],
                'pageSubnav' => [],
                'dashboardBootstrap' => [
                    'authorized' => true,
                    'status' => 'ok',
                    'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')) . ' Store',
                    'links' => $dashboardLinks,
                ],
            ])
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

}
