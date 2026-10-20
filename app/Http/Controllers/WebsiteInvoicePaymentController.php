<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteInvoicePaymentService;
use App\Services\ManagedWebsite\WebsiteQuoteOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WebsiteInvoicePaymentController extends Controller
{
    public function show(Request $request, string $token, WebsiteInvoicePaymentService $payments)
    {
        [$tenant, $order] = $this->resolve($request, $token);

        return response()->view('managed-website.invoice', ['tenant' => $tenant, 'order' => $order->load('lines'),
            'balance' => $payments->balance($order), 'receipts' => $order->payments()->where('metadata->paid_confirmed', true)->get(),
            'refundActivity' => $payments->hasRefundActivity($order),
            'cardAvailable' => $payments->cardAvailable($tenant),
            'bankAvailable' => $payments->bankAvailable($tenant),
            'pendingPayment' => $order->payments()->where('metadata->checkout_version', 2)->whereIn('status', ['creating', 'pending', 'processing'])->latest('id')->first()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function accept(Request $request, string $token, WebsiteQuoteOrderService $quotes)
    {
        [, $order] = $this->resolve($request, $token);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'agree' => ['required', 'accepted']]);
        $quotes->accept($order, (int) $data['version']);

        return back()->with('status', 'Quote accepted. You can now pay your invoice.');
    }

    public function pay(Request $request, string $token, WebsiteInvoicePaymentService $payments)
    {
        [, $order] = $this->resolve($request, $token);
        $data = $request->validate(['installment' => ['required', 'in:full,deposit,balance'], 'version' => ['required', 'integer', 'min:1'],
            'payment_method' => ['sometimes', 'in:card,us_bank_account']]);
        abort_unless((int) data_get($order->service_request, 'invoice.version') === (int) $data['version'], 409, 'This invoice changed. Reload before paying.');
        try {
            $url = $payments->checkout($order, $data['installment'], $request->getSchemeAndHttpHost(), $data['payment_method'] ?? 'card');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw ValidationException::withMessages(['payment' => 'Payment setup is unconfirmed. Reopen this invoice to recover the same attempt.']);
        }

        return redirect()->away($url)->header('Referrer-Policy', 'no-referrer');
    }

    public function refresh(Request $request, string $token, WebsiteInvoicePaymentService $payments)
    {
        [, $order] = $this->resolve($request, $token);
        foreach ($order->payments()->where('metadata->checkout_version', 2)->whereIn('status', ['creating', 'pending', 'processing'])->get() as $payment) {
            $payments->reconcile($payment);
        }

        return back()->with('status', 'Payment status checked.');
    }

    private function resolve(Request $request, string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{56}$/D', $token), 404);
        $tenant = $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $websites = app(ManagedWebsiteService::class);
        $payload = $websites->publicPage($tenant, '');
        abort_unless($payload && $websites->publicHostAllowed($payload['site'], $request->getHost())
            && app(WebsiteInvoicePaymentService::class)->enabled($tenant), 404);
        $order = WebsiteOrder::forTenant($tenant)->where('tenant_site_id', $payload['site']->id)->where('lookup_token', $token)
            ->whereIn('source', ['staff_quote', 'staff_draft_invoice'])->firstOrFail();
        abort_unless(data_get($order->service_request, 'invoice.issued_at'), 404);

        return [$tenant, $order];
    }
}
