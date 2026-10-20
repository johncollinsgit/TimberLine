<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Checkout;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplacePayPal;
use Illuminate\Http\Request;

class PayPalController extends Controller
{
    private function owned(Request $request, string $uuid): Checkout
    {
        return Checkout::where('uuid', $uuid)->where('provider', 'paypal')->where('session_hash', app(MarketplaceCheckout::class)->owner($request))->firstOrFail();
    }

    public function show(Request $request, string $uuid)
    {
        $checkout = $this->owned($request, $uuid);
        if ($checkout->status === 'paid') {
            return redirect('/checkout/'.$uuid);
        }

        return view('marketplace.paypal', ['checkout' => $checkout]);
    }

    public function act(Request $request, string $uuid, MarketplacePayPal $paypal)
    {
        $checkout = $this->owned($request, $uuid);
        $action = $request->validate(['action' => 'required|in:create,capture,cancel,status'])['action'];
        if ($action === 'cancel') {
            $paypal->cancel($checkout);

            return redirect('/cart');
        }
        if ($action === 'status') {
            $paypal->reconcile($checkout);

            return back();
        }
        if ($action === 'capture') {
            $paypal->capture($checkout);

            return redirect('/checkout/'.$uuid);
        }
        $data = $request->validate(['name' => 'required|string|max:200', 'email' => 'required|email:rfc|max:254', 'address' => 'nullable|array', 'address.line1' => 'required_if:delivery,shipping|nullable|string|max:200', 'address.line2' => 'nullable|string|max:200', 'address.city' => 'required_if:delivery,shipping|nullable|string|max:100', 'address.state' => 'required_if:delivery,shipping|nullable|regex:/^[A-Z]{2}$/', 'address.postal_code' => 'required_if:delivery,shipping|nullable|regex:/^[0-9]{5}(-[0-9]{4})?$/', 'address.country' => 'nullable|in:US']);
        if ($checkout->group_key === 'shipping') {
            $request->validate(['address.line1' => 'required', 'address.city' => 'required', 'address.state' => 'required', 'address.postal_code' => 'required', 'address.country' => 'required|in:US']);
        }

        return redirect()->away($paypal->create($checkout, $data, $request->getSchemeAndHttpHost()));
    }
}
