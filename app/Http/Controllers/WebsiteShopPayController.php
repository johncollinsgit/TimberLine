<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteCart;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Models\WebsiteShopPayAttempt;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteShopPayCheckoutService;
use Illuminate\Http\Request;

class WebsiteShopPayController extends Controller
{
    public function show(Request $request, string $uuid, WebsiteShopPayCheckoutService $wallet)
    {
        [$site, $payment] = $this->payment($request, $uuid);
        $attempt = $wallet->attempt($payment);
        $merchant = $wallet->merchant($attempt);
        $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id);

        return response()->view('managed-website.shop-pay', ['site' => $site, 'payment' => $payment,
            'attempt' => $attempt, 'order' => $order, 'initial' => $wallet->initial($payment),
            'shopId' => $merchant->shop_id, 'clientId' => $merchant->client_id,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function event(Request $request, string $uuid, WebsiteShopPayCheckoutService $wallet)
    {
        [, $payment] = $this->payment($request, $uuid);
        $data = $request->validate(['event' => ['required', 'in:session,quote,submit,status'],
            'contact' => ['nullable', 'array'], 'contact.address1' => ['nullable', 'string', 'max:190'],
            'contact.address2' => ['nullable', 'string', 'max:190'], 'contact.city' => ['nullable', 'string', 'max:120'],
            'contact.provinceCode' => ['nullable', 'string', 'max:10'], 'contact.postalCode' => ['nullable', 'string', 'max:20'],
            'contact.countryCode' => ['nullable', 'string', 'max:2'],
            'quote_hash' => ['required_if:event,submit', 'string', 'size:64'],
            'payment_method_token' => ['required_if:event,submit', 'string', 'max:20000']]);
        if ($data['event'] === 'session') {
            $result = $wallet->session($payment);
        } elseif ($data['event'] === 'quote') {
            $result = $wallet->quote($payment, $data['contact'] ?? null);
        } else {
            if ($data['event'] === 'submit') {
                $wallet->submit($payment, $data['payment_method_token'], $data['quote_hash']);
            }
            $wallet->reconcile($payment);
            $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id);
            $result = ['status' => $wallet->attempt($payment)->status, 'payment_status' => $payment->fresh()->status,
                'confirmation_url' => data_get($payment->fresh()->metadata, 'paid_confirmed')
                    ? route('managed-website.store.success', ['order' => $order->number, 'token' => $order->lookup_token], false) : null];
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function webhook(Request $request, WebsiteShopPayCheckoutService $wallet)
    {
        abort_if(strlen($request->getContent()) > 1024 * 1024, 413);
        abort_unless(in_array($request->header('X-Shopify-Topic'), ['orders/paid', 'orders/updated', 'refunds/create'], true), 400);
        $source = $request->input('source_identifier');
        if (is_string($source)) {
            $attempt = WebsiteShopPayAttempt::where('source_identifier', $source)->firstOrFail();
        } else {
            $orderId = (string) $request->input('order_id', '');
            abort_unless(preg_match('/\A[1-9][0-9]{0,19}\z/D', $orderId), 400);
            $attempt = WebsiteShopPayAttempt::where('shopify_order_id', 'gid://shopify/Order/'.$orderId)->firstOrFail();
        }
        $merchant = $wallet->merchant($attempt);
        abort_unless($merchant->webhook_secret && $request->header('X-Shopify-Shop-Domain') === $merchant->shop_domain
            && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $merchant->webhook_secret, true)), (string) $request->header('X-Shopify-Hmac-Sha256')), 401);
        // The authenticated event merely schedules an original-account read.
        // Its financial fields and arrival order never mark an order paid.
        $wallet->reconcile(WebsitePayment::query()->forTenantId($attempt->tenant_id)->findOrFail($attempt->website_payment_id));

        return response()->json(['received' => true]);
    }

    private function payment(Request $request, string $uuid): array
    {
        $tenant = $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $websites = app(ManagedWebsiteService::class);
        $payload = $websites->publicPage($tenant, '');
        abort_unless($payload && $websites->publicHostAllowed($payload['site'], $request->getHost()), 404);
        $site = $payload['site'];
        $attempt = WebsiteShopPayAttempt::query()->forTenant($tenant)->where('uuid', $uuid)->firstOrFail();
        WebsiteCart::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->whereKey($attempt->website_cart_id)
            ->where('token', (string) $request->session()->get('website_cart_'.$site->id))->firstOrFail();
        $payment = WebsitePayment::query()->forTenant($tenant)->where('website_order_id', $attempt->website_order_id)->findOrFail($attempt->website_payment_id);

        return [$site, $payment];
    }
}
