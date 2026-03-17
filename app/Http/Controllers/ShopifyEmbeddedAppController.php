<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Services\Marketing\BirthdayReportingService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedAppController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        BirthdayReportingService $birthdayReporting
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
                    'headline' => 'Open this app from Shopify Admin',
                    'subheadline' => 'This page is meant to load inside your Shopify admin so it can verify the store context.',
                    'appNavigation' => $this->embeddedAppNavigation('dashboard'),
                    'pageActions' => [],
                    'cards' => [],
                    'setupNote' => 'If you still need the dedicated rewards page on the storefront, create a page named Your Rewards with the page.forestry-rewards template and publish it.',
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
                    'headline' => 'We could not verify this Shopify request',
                    'subheadline' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
                    'appNavigation' => $this->embeddedAppNavigation('dashboard'),
                    'pageActions' => [],
                    'cards' => [],
                    'setupNote' => null,
                ]),
                401
            );
        }

        /** @var array<string,mixed> $store */
        $store = $context['store'];
        $summary = $birthdayReporting->summary();
        $rewardSummary = $birthdayReporting->rewardSummary();
        $storeRecord = ShopifyStore::query()->where('store_key', $store['key'])->first();
        $hasProxySecret = trim((string) config('marketing.shopify.app_proxy_secret', '')) !== ''
            || trim((string) config('services.shopify.stores.' . $store['key'] . '.client_secret', '')) !== '';

        $cards = [
            [
                'label' => 'Storefront Rewards',
                'status' => ($storeRecord !== null && (bool) config('marketing.shopify.app_proxy_enabled', true) && $hasProxySecret)
                    ? 'Connected'
                    : 'Needs attention',
                'tone' => ($storeRecord !== null && (bool) config('marketing.shopify.app_proxy_enabled', true) && $hasProxySecret)
                    ? 'ok'
                    : 'warning',
                'body' => ($storeRecord !== null && (bool) config('marketing.shopify.app_proxy_enabled', true) && $hasProxySecret)
                    ? 'The storefront rewards endpoints are connected to this app and ready to answer live requests.'
                    : 'The storefront connection is missing install or proxy config.',
                'meta' => [
                    'Proxy path' => '/apps/forestry/*',
                    'Store install' => $storeRecord?->installed_at ? 'Installed' : 'Missing install record',
                ],
            ],
            [
                'label' => 'Birthday Rewards',
                'status' => ((bool) config('marketing.birthday_rewards.enabled', true) && (int) ($summary['with_birthday'] ?? 0) > 0)
                    ? 'Running'
                    : 'Not ready',
                'tone' => ((bool) config('marketing.birthday_rewards.enabled', true) && (int) ($summary['with_birthday'] ?? 0) > 0)
                    ? 'ok'
                    : 'warning',
                'body' => 'Backstage is tracking birthdays, reward states, and order usage for this store.',
                'meta' => [
                    'Customers with birthdays' => number_format((int) ($summary['with_birthday'] ?? 0)),
                    'Activated rewards' => number_format((int) ($rewardSummary['activated'] ?? 0)),
                    'Redeemed rewards' => number_format((int) ($rewardSummary['redeemed'] ?? 0)),
                ],
            ],
            [
                'label' => 'Store Page',
                'status' => 'Check page publish',
                'tone' => 'info',
                'body' => 'If the dedicated rewards page is not live yet, publish a Shopify page named Your Rewards and assign the page.forestry-rewards template.',
                'meta' => [
                    'Page title' => 'Your Rewards',
                    'Handle' => 'rewards',
                    'Template' => 'page.forestry-rewards',
                ],
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
                'headline' => 'Forestry rewards are connected',
                'subheadline' => 'Backstage manages the reward logic. Your storefront shows it to shoppers. This page is the quick health view for the Shopify side.',
                'appNavigation' => $this->embeddedAppNavigation('dashboard'),
                'pageActions' => [
                    [
                        'label' => 'Open Rewards Admin',
                        'href' => route('shopify.embedded.rewards', [], false),
                    ],
                    [
                        'label' => 'Open Birthdays in Backstage',
                        'href' => route('birthdays.customers'),
                    ],
                    [
                        'label' => 'Open Marketing Overview',
                        'href' => route('marketing.overview'),
                    ],
                    [
                        'label' => 'Open Birthday Rewards',
                        'href' => route('birthdays.rewards'),
                    ],
                ],
                'cards' => $cards,
                'setupNote' => 'The storefront helper is already live in account, cart, and cart drawer. The only remaining storefront setup step is publishing the dedicated rewards page if you want that page in navigation.',
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
