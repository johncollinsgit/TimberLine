<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\WebsiteOrderNotificationService;
use App\Services\ManagedWebsite\WebsiteShippingAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteOrderNotificationController extends Controller
{
    public function retry(Request $request, WebsiteOrder $order, int $notificationId, WebsiteOrderNotificationService $notifications): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000'], 'confirm_send' => ['required', 'accepted']]);
        $notifications->retryBlocked($order, $notificationId, $request->user(), $data['reason']);

        return back()->with('status', 'The reviewed order update was queued. Transport acceptance will be recorded separately.');
    }

    public function resolve(Request $request, WebsiteOrder $order, int $notificationId, WebsiteOrderNotificationService $notifications): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000'],
            'outcome' => ['required', 'in:accepted,confirmed_not_accepted'], 'confirm_evidence' => ['required', 'accepted']]);
        $notifications->resolveUnknown($order, $notificationId, $request->user(), $data['outcome'], $data['reason']);

        return back()->with('status', 'Provider evidence recorded. No email was sent by this action.');
    }

    private function authorizeOrder(Request $request, WebsiteOrder $order): void
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $order->tenant_id === $tenant->id, 404);
        app(WebsiteShippingAccessService::class)->assertCanManage($tenant, $request->user());
    }
}
