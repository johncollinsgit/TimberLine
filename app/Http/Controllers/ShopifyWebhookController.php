<?php

namespace App\Http\Controllers;

use App\Jobs\ShopifyUpsertOrder;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyWebhookController extends Controller
{
    public function ordersCreate(Request $request): Response
    {
        return $this->handleOrderWebhook($request);
    }

    public function ordersUpdated(Request $request): Response
    {
        return $this->handleOrderWebhook($request);
    }

    public function ordersCancelled(Request $request): Response
    {
        return $this->handleOrderWebhook($request);
    }

    public function refundsCreate(Request $request): Response
    {
        return $this->handleOrderWebhook($request);
    }

    protected function handleOrderWebhook(Request $request): Response
    {
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain');
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256');
        $payload = $request->getContent();

        $store = ShopifyStores::findByShopDomain($shopDomain);
        if (!$store || empty($store['secret'])) {
            return response('Unknown shop.', 404);
        }

        if (!$this->isValidHmac($payload, $hmac, (string) $store['secret'])) {
            return response('Invalid signature.', 401);
        }

        $data = $request->json()->all();
        if (!is_array($data) || empty($data)) {
            return response('Invalid payload.', 422);
        }

        ShopifyUpsertOrder::dispatch($store['key'], $data);

        return response('ok', 200);
    }

    protected function isValidHmac(string $payload, string $hmacHeader, string $secret): bool
    {
        if ($payload === '' || $hmacHeader === '' || $secret === '') {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        return hash_equals($calculated, $hmacHeader);
    }
}
