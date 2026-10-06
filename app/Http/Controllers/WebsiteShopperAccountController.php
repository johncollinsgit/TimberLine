<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WebsiteShopperAccountController extends Controller
{
    public function index(Request $request, ManagedWebsiteService $websites): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $customer = $this->sessionCustomer($request, $tenant, $site);
        $orders = $customer ? WebsiteOrder::query()->forTenant($tenant)
            ->where('tenant_site_id', $site->id)->where('website_customer_id', $customer->id)
            ->with('lines', 'shipments')->latest()->paginate(10) : null;

        return view('managed-website.account', compact('site', 'customer', 'orders'));
    }

    public function requestLink(Request $request, ManagedWebsiteService $websites): RedirectResponse
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $email = strtolower(trim((string) $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']));
        $token = Str::random(64);
        $key = $this->tokenKey($token);
        Cache::put($key, ['tenant_id' => $tenant->id, 'site_id' => $site->id, 'email' => $email], now()->addMinutes(15));
        $url = $request->getSchemeAndHttpHost().route('managed-website.store.account.verify', ['token' => $token], false);
        $brand = $tenant->brandProfile?->display_name ?: $tenant->name;
        try {
            Mail::raw("Sign in to your {$brand} account and view your order history:\n\n{$url}\n\nThis link expires in 15 minutes. If you didn't request it, you can ignore this email.", function ($message) use ($email, $brand): void {
                $message->to($email)->subject('Your '.$brand.' sign-in link');
            });
        } catch (\Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        }

        return back()->with('account_status', 'Check your email for a sign-in link. It expires in 15 minutes.');
    }

    public function verify(Request $request, string $token, ManagedWebsiteService $websites): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $payload = Cache::get($this->tokenKey($token));
        abort_unless(is_array($payload) && (int) ($payload['tenant_id'] ?? 0) === (int) $tenant->id && (int) ($payload['site_id'] ?? 0) === (int) $site->id, 404);

        return view('managed-website.account-verify', compact('site', 'token'));
    }

    public function consume(Request $request, string $token, ManagedWebsiteService $websites): RedirectResponse
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $payload = Cache::get($this->tokenKey($token));
        abort_unless(is_array($payload) && (int) ($payload['tenant_id'] ?? 0) === (int) $tenant->id && (int) ($payload['site_id'] ?? 0) === (int) $site->id, 404);
        $payload = Cache::pull($this->tokenKey($token));
        abort_unless(is_array($payload) && (int) ($payload['tenant_id'] ?? 0) === (int) $tenant->id && (int) ($payload['site_id'] ?? 0) === (int) $site->id, 404);
        $email = (string) ($payload['email'] ?? '');
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 404);
        $customer = WebsiteCustomer::query()->firstOrCreate(['tenant_id' => $tenant->id, 'email' => $email], ['status' => 'active']);
        abort_unless($customer->status === 'active', 403);
        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($site), (int) $customer->id);

        return redirect()->route('managed-website.store.account')->with('account_status', 'Welcome back.');
    }

    public function logout(Request $request, ManagedWebsiteService $websites): RedirectResponse
    {
        [, $site] = $this->publicSite($request, $websites);
        $request->session()->forget($this->sessionKey($site));
        $request->session()->regenerateToken();

        return redirect()->route('managed-website.store.account');
    }

    public function updateProfile(Request $request, ManagedWebsiteService $websites): RedirectResponse
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $customer = $this->sessionCustomer($request, $tenant, $site);
        abort_unless($customer, 403);
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:80'],
        ]);
        $customer->forceFill([
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ])->save();

        return redirect()->route('managed-website.store.account')->with('account_status', 'Contact details saved.');
    }

    public function order(Request $request, string $number, ManagedWebsiteService $websites): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $customer = $this->sessionCustomer($request, $tenant, $site);
        abort_unless($customer, 403);
        $order = WebsiteOrder::query()->forTenant($tenant)
            ->where('tenant_site_id', $site->id)->where('website_customer_id', $customer->id)
            ->where('number', $number)->with('lines', 'shipments')->firstOrFail();

        return view('managed-website.account-order', compact('site', 'customer', 'order'));
    }

    /** @return array{Tenant,TenantSite} */
    private function publicSite(Request $request, ManagedWebsiteService $websites): array
    {
        $tenant = $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $payload = $websites->publicPage($tenant, '');
        abort_unless($payload !== null && $websites->publicHostAllowed($payload['site'], $request->getHost()), 404);

        return [$tenant, $payload['site']];
    }

    private function sessionCustomer(Request $request, Tenant $tenant, TenantSite $site): ?WebsiteCustomer
    {
        $id = (int) $request->session()->get($this->sessionKey($site), 0);

        return $id > 0 ? WebsiteCustomer::query()->forTenant($tenant)->whereKey($id)->where('status', 'active')->first() : null;
    }

    private function sessionKey(TenantSite $site): string
    {
        return 'website_shopper_'.$site->id;
    }

    private function tokenKey(string $token): string
    {
        return 'website-shopper-link:'.hash('sha256', $token);
    }
}
