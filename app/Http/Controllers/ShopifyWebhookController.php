<?php

namespace App\Http\Controllers;

use App\Jobs\ShopifySyncCustomerFromWebhook;
use App\Jobs\ShopifyUpsertOrder;
use App\Services\Marketing\IntegrationHealthEventRecorder;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function ordersCreate(Request $request): Response
    {
        return $this->handleOrderWebhook($request, app(TenantResolver::class));
    }

    public function ordersUpdated(Request $request): Response
    {
        return $this->handleOrderWebhook($request, app(TenantResolver::class));
    }

    public function ordersCancelled(Request $request): Response
    {
        return $this->handleOrderWebhook($request, app(TenantResolver::class));
    }

    public function refundsCreate(Request $request): Response
    {
        return $this->handleOrderWebhook($request, app(TenantResolver::class));
    }

    public function customersCreate(Request $request): Response
    {
        return $this->handleCustomerWebhook(
            $request,
            'customers/create',
            app(TenantResolver::class),
            app(IntegrationHealthEventRecorder::class)
        );
    }

    public function customersUpdated(Request $request): Response
    {
        return $this->handleCustomerWebhook(
            $request,
            'customers/update',
            app(TenantResolver::class),
            app(IntegrationHealthEventRecorder::class)
        );
    }

    protected function handleOrderWebhook(Request $request, TenantResolver $tenantResolver): Response
    {
        $resolved = $this->resolveVerifiedWebhookRequest($request);
        if ($resolved['status'] !== 'ok') {
            return response((string) ($resolved['message'] ?? 'Invalid request.'), (int) ($resolved['code'] ?? 422));
        }

        $store = (array) $resolved['store'];
        $data = (array) $resolved['data'];
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        ShopifyUpsertOrder::dispatch((string) $store['key'], $data, $tenantId);

        return response('ok', 200);
    }

    protected function handleCustomerWebhook(
        Request $request,
        string $topic,
        TenantResolver $tenantResolver,
        IntegrationHealthEventRecorder $healthEventRecorder
    ): Response
    {
        $resolved = $this->resolveVerifiedWebhookRequest($request);
        if ($resolved['status'] !== 'ok') {
            return response((string) ($resolved['message'] ?? 'Invalid request.'), (int) ($resolved['code'] ?? 422));
        }

        $store = (array) $resolved['store'];
        $data = (array) $resolved['data'];
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            Log::warning('shopify customer webhook skipped: unresolved tenant context', [
                'topic' => $topic,
                'store_key' => $store['key'] ?? null,
                'shop' => $store['shop'] ?? null,
            ]);

            $healthEventRecorder->record([
                'provider' => 'shopify',
                'tenant_id' => null,
                'store_key' => trim((string) ($store['key'] ?? '')) ?: null,
                'event_type' => 'tenant_context_unresolved',
                'severity' => 'warning',
                'status' => 'open',
                'context' => [
                    'topic' => $topic,
                    'source' => 'shopify_webhook_controller',
                    'reason' => 'tenant_context_unresolved',
                ],
            ]);

            return response('Tenant context unresolved.', 202);
        }

        ShopifySyncCustomerFromWebhook::dispatch(
            ['key' => (string) ($store['key'] ?? ''), 'tenant_id' => $tenantId],
            $data,
            $tenantId,
            $topic
        );

        return response('ok', 200);
    }

    /**
     * @return array{status:string,code?:int,message?:string,store?:array<string,mixed>,data?:array<string,mixed>}
     */
    protected function resolveVerifiedWebhookRequest(Request $request): array
    {
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain');
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256');
        $payload = $request->getContent();

        $store = ShopifyStores::findByShopDomain($shopDomain);
        if (! $store || empty($store['secret'])) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Unknown shop.'];
        }

        if (! $this->isValidHmac($payload, $hmac, (string) $store['secret'])) {
            return ['status' => 'error', 'code' => 401, 'message' => 'Invalid signature.'];
        }

        $data = $request->json()->all();
        if (! is_array($data) || $data === []) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Invalid payload.'];
        }

        if (trim((string) ($store['key'] ?? '')) === '') {
            return ['status' => 'error', 'code' => 422, 'message' => 'Invalid store context.'];
        }

        return [
            'status' => 'ok',
            'store' => $store,
            'data' => $data,
        ];
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
