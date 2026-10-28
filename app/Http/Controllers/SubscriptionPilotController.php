<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionPilotService;
use Illuminate\Http\Request;

class SubscriptionPilotController extends Controller
{
    public function refresh(Request $request, int $member, \App\Services\Subscriptions\SubscriptionPilotShopifyReadService $reads)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        try {
            $count = $reads->refresh($tenant->id, $request->user(), $member);

            return back()->with('pilot_status', $count.' matching subscription orders checked. Billing verification remains separate.');
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            return back()->with('pilot_error', 'The Shopify read could not be completed. Saved evidence was retained; no payment or provider change was attempted.');
        }
    }

    public function index(Request $request, SubscriptionPilotService $pilots)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $input = $request->validate(['member' => ['nullable', 'integer', 'min:1']]);

        return view('subscriptions.pilot', ['tenant' => $tenant,
            'pilot' => $pilots->dashboard((int) $tenant->id, $request->user(), isset($input['member']) ? (int) $input['member'] : null)]);
    }
}
