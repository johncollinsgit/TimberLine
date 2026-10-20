<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Connection;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplaceWallet;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    private function owned(Request $request, string $uuid): Checkout
    {
        return Checkout::where('uuid', $uuid)->where('provider', 'shop_pay')->where('session_hash', app(MarketplaceCheckout::class)->owner($request))->firstOrFail();
    }

    public function show(Request $request, string $uuid, MarketplaceWallet $wallet)
    {
        $checkout = $this->owned($request, $uuid);
        if ($checkout->status === 'paid') {
            return redirect('/checkout/'.$uuid);
        }$connection = Connection::findOrFail($checkout->connection_id);

        return view('marketplace.wallet', ['checkout' => $checkout, 'shopId' => (int) \Illuminate\Support\Str::afterLast($connection->shop_gid, '/'), 'clientId' => $connection->credentials['client_id'], 'initial' => $wallet->frontend($checkout, $wallet->initial($checkout))]);
    }

    public function event(Request $request, string $uuid, MarketplaceWallet $wallet)
    {
        $checkout = $this->owned($request, $uuid);
        $data = $request->validate(['event' => 'required|in:session,quote,submit,status', 'contact' => 'nullable|array', 'contact.countryCode' => 'nullable|in:US', 'contact.postalCode' => 'nullable|string|max:20', 'contact.provinceCode' => 'nullable|string|max:8', 'contact.city' => 'nullable|string|max:100', 'contact.address1' => 'nullable|string|max:200', 'contact.address2' => 'nullable|string|max:200', 'contact.firstName' => 'nullable|string|max:100', 'contact.lastName' => 'nullable|string|max:100', 'contact.email' => 'nullable|email:rfc|max:254', 'contact.phone' => 'nullable|string|max:40', 'quote_hash' => 'nullable|string|size:64', 'request_uuid' => 'nullable|uuid', 'payment_method_token' => 'nullable|string|max:20000']);
        if ($data['event'] === 'session') {
            return response()->json($wallet->session($checkout));
        }
        if ($data['event'] === 'status') {
            $wallet->reconcile($checkout);

            return response()->json(['status' => $checkout->fresh()->status]);
        }
        if ($data['event'] === 'quote') {
            return response()->json($wallet->quote($checkout, $data['contact'] ?? null));
        }
        $wallet->submit($checkout, $data['payment_method_token'] ?? '', $data['quote_hash'] ?? '', $data['request_uuid'] ?? '');

        return response()->json(['submitted' => true]);
    }

    public function cancel(Request $request, string $uuid, MarketplaceWallet $wallet)
    {
        $wallet->cancel($this->owned($request, $uuid));

        return redirect('/cart')->with('status', 'Choose another available payment method.');
    }

    public function connect(Request $request, MarketplaceWallet $wallet)
    {
        $data = $request->validate(['shop_domain' => ['required', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/'], 'shop_id' => ['required', 'regex:/^[1-9][0-9]{0,19}$/'], 'client_id' => 'required|uuid', 'admin_token' => 'required|string|max:4096', 'storefront_token' => 'required|string|max:4096', 'webhook_secret' => 'required|string|max:4096', 'merchant_confirmed' => 'required|accepted', 'origin_verified' => 'required|accepted']);
        $data['merchant_confirmed'] = $request->boolean('merchant_confirmed');
        $data['origin_verified'] = $request->boolean('origin_verified');
        $data['test_mode'] = $request->boolean('test_mode');
        $wallet->connect($data, $request->user()->id);

        return back()->with('status','Shop Pay merchant API identity verified. Wallet collection still requires its independent test and launch checks.');
    }
}
