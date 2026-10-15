<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use App\Services\ManagedWebsite\WebsiteShippingAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WebsiteCarrierSetupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        app(WebsiteShippingAccessService::class)->assertCanManage($tenant, $request->user());
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        app(WebsiteCatalogService::class)->assertEditor($site, $request->user());
        $validator = Validator::make($request->only('api_key', 'webhook_secret', 'mode', 'label'), [
            'api_key' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'webhook_secret' => ['required', 'string', 'min:16', 'max:255', 'not_regex:/[\r\n]/'],
            'mode' => ['required', 'in:test,production'], 'label' => ['required', 'string', 'max:190'],
        ]);
        if ($validator->fails()) {
            // Credential fields must never be flashed back to the session/form.
            return back()->withErrors($validator)->withInput($request->only('mode', 'label'));
        }
        $data = $validator->validated();
        DB::transaction(function () use ($site, $request, $data): void {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->whereKey($site->id)->lockForUpdate()->firstOrFail();
            // Keep the original encrypted credentials available to older shipments.
            // A new credential set invalidates quotes; it never retargets an attempt.
            $connection = IntegrationConnection::query()->create([
                'tenant_id' => $site->tenant_id, 'provider' => 'easypost', 'external_account_id' => 'native:'.Str::uuid(),
                'external_account_label' => $data['label'], 'status' => IntegrationConnection::STATUS_CONNECTED,
                'access_token' => $data['api_key'], 'external_account_secret' => $data['webhook_secret'],
                'connected_by_user_id' => $request->user()->id, 'connected_at' => now(),
                'metadata' => ['native_shipping' => ['version' => 1, 'mode' => $data['mode'], 'verified_at' => null]],
            ]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $request->user(), 'shipping.credentials_saved', [
                'connection_id' => $connection->id, 'mode' => $data['mode'],
            ]);
        });

        return back()->with('status', 'Carrier credentials saved. Configure the matching EasyPost webhook and verify test rates before launch. Shipping remains subject to its rollout gates.');
    }
}
