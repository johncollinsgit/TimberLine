<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\ManagedWebsite\ShopPayWalletConnectionService;
use App\Services\Tenancy\TenantExperienceProfileService;
use Illuminate\Http\Request;

class ShopPaySetupController extends Controller
{
    public function index(Request $request, ShopPayWalletConnectionService $wallet)
    {
        $tenant = $this->tenant($request);
        $site = TenantSite::query()->forTenant($tenant)->first();
        $profile = app(TenantExperienceProfileService::class)->forTenant($tenant->id, $request->user(), $tenant);

        return response()->view('billing.shop-pay-setup', [
            'tenant' => $tenant, 'site' => $site, 'profile' => $profile,
            'connection' => $wallet->connection($tenant), 'blockers' => $wallet->blockers($tenant, $site),
            'origin' => $site ? $wallet->origin($site) : null,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function save(Request $request, ShopPayWalletConnectionService $wallet)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'shop_domain' => ['required', 'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com\z/D'],
            'shop_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'client_id' => ['required', 'string', 'max:190'], 'environment' => ['required', 'in:test,live'],
            'storefront_access_token' => ['nullable', 'string', 'max:4096', 'regex:/\A[^\s\x00-\x1f\x7f]+\z/D'],
            'admin_access_token' => ['nullable', 'string', 'max:4096', 'regex:/\A[^\s\x00-\x1f\x7f]+\z/D'],
            'webhook_secret' => ['nullable', 'string', 'max:4096', 'regex:/\A[^\s\x00-\x1f\x7f]+\z/D'],
            'dedicated_store_confirmed' => ['required', 'accepted'],
        ]);
        $wallet->save($tenant, $request->user(), $data);

        return back()->with('status', 'Shop Pay settings saved. Verify the connection before enabling checkout.');
    }

    public function verify(Request $request, ShopPayWalletConnectionService $wallet)
    {
        $tenant = $this->tenant($request);
        $request->validate(['origin_confirmed' => ['required', 'accepted']]);
        $connection = $wallet->test($tenant, TenantSite::query()->forTenant($tenant)->firstOrFail(), $request->user());

        return back()->with('status', $connection->connection_status === 'connected'
            ? 'Merchant identity and a payment-free Wallet session verified. Customer collection still requires launch verification.'
            : 'Connection could not be verified. '.$connection->last_error);
    }

    public function availability(Request $request, ShopPayWalletConnectionService $wallet)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $wallet->setEnabled($tenant, TenantSite::query()->forTenant($tenant)->firstOrFail(), $request->user(), (bool) $data['enabled']);

        return back()->with('status', $data['enabled'] ? 'Shop Pay is offered on eligible native website checkouts.' : 'Shop Pay is hidden from new checkouts. Existing payments can still be recovered.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && app(TenantPaymentSetupAccess::class)->allows($tenant, $request->user()), 403);

        return $tenant;
    }
}
