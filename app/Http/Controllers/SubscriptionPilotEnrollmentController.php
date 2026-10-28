<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionPilotExecutionService;
use Illuminate\Http\Request;

class SubscriptionPilotEnrollmentController extends Controller
{
    public function review(Request $request, int $member)
    {
        $tenant = $this->tenant($request);
        $row = SubscriptionPilotMember::query()->forTenantId($tenant->id)->findOrFail($member);

        return $this->screen($tenant, $row);
    }

    public function quote(Request $request, int $member, SubscriptionPilotExecutionService $execution)
    {
        $tenant = $this->tenant($request);
        $row = SubscriptionPilotMember::query()->forTenantId($tenant->id)->findOrFail($member);
        $data = $request->validate(['shipping_amount' => ['required', 'string', 'regex:/^[0-9]{1,4}(?:\.[0-9]{1,2})?$/D'],
            'tax_rate_percent' => ['required', 'string', 'regex:/^[0-9]{1,3}(?:\.[0-9]{1,2})?$/D']]);
        $input = $request->only(['recharge_connection_id', 'tax_basis']);
        $input += ['shipping_cents' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::cents($data['shipping_amount']),
            'tax_rate_bps' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::cents($data['tax_rate_percent']),
            'tax_shipping' => $request->boolean('tax_shipping')];

        return $this->screen($tenant, $row, ['quote' => $execution->preview($tenant->id, $request->user(), $member, $input), 'input' => $input]);
    }

    public function prepare(Request $request, int $member, SubscriptionPilotExecutionService $execution)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['terms_hash' => ['required', 'string', 'size:64'], 'reviewed' => ['accepted']]);
        $input = $request->only(['recharge_connection_id', 'shipping_cents', 'tax_rate_bps', 'tax_shipping', 'tax_basis']);
        $input['tax_shipping'] = $request->boolean('tax_shipping');
        $row = $execution->prepare($tenant->id, $request->user(), $member, $input, $data['terms_hash']);

        return redirect()->route('subscriptions.pilot.enrollment.review', ['member' => $member, 'tenant' => $tenant->slug])
            ->with('authorization_link', encrypt($execution->issueAccess($row)));
    }

    public function access(Request $request, int $member, SubscriptionPilotExecutionService $execution)
    {
        $tenant = $this->tenant($request);
        $row = SubscriptionPilotEnrollment::query()->forTenantId($tenant->id)->where('member_id', $member)->firstOrFail();

        return redirect()->route('subscriptions.pilot.enrollment.review', ['member' => $member, 'tenant' => $tenant->slug])
            ->with('authorization_link', encrypt($execution->issueAccess($row)));
    }

    public function activate(Request $request, int $member, SubscriptionPilotExecutionService $execution)
    {
        $tenant = $this->tenant($request);
        $request->validate(['reviewed' => ['accepted']]);
        $row = SubscriptionPilotEnrollment::query()->forTenantId($tenant->id)->where('member_id', $member)->firstOrFail();
        $execution->activate($row, $request->user());

        return redirect()->route('subscriptions.pilot.index', ['tenant' => $tenant->slug])->with('pilot_status', 'The selected Stripe pilot is active. Other Recharge subscriptions are unchanged.');
    }

    public function connectRecharge(Request $request, \App\Services\Subscriptions\SubscriptionPilotRechargeSetup $setup)
    {
        $tenant = $this->tenant($request);
        // Standard password exclusion prevents private credentials being flashed into old input.
        $data = $request->validate(['shopify_store_id' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'min:16', 'max:4096']]);
        try {
            $setup->connect($tenant->id, $request->user(), $data['shopify_store_id'], $data['password']);
        } catch (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && in_array($e->getStatusCode(), [403, 404], true)) {
                throw $e;
            }

            return back()->withErrors(['connection' => 'The private read-only connection needs review. Confirm the store and required scopes; the credential was not displayed.']);
        }

        return back()->with('pilot_status', 'Recharge store ownership and read-only credential verified. No billing was changed.');
    }

    private function screen(Tenant $tenant, SubscriptionPilotMember $member, array $extra = [])
    {
        return response()->view('subscriptions.pilot-enrollment', $extra + ['tenant' => $tenant, 'member' => $member,
            'enrollment' => SubscriptionPilotEnrollment::query()->forTenantId($tenant->id)->where('member_id', $member->id)->first(),
            'connections' => SubscriptionRechargeConnection::query()->forTenantId($tenant->id)->where('status', 'verified')->get()])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows($tenant, $request->user()), 403);
        app(\App\Services\Subscriptions\SubscriptionAccessService::class)->assertManager($tenant->id, $request->user());

        return $tenant;
    }
}
